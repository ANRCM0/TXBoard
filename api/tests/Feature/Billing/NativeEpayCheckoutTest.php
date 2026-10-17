<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\NativePaymentCheckout;
use App\Domains\Billing\NativeBillingRecovery;
use App\Domains\Billing\NativeOrderLifecycle;
use App\Domains\Billing\NativeRechargeService;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use App\Models\Order;
use App\Models\User;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\NativePaymentTestCase;

final class NativeEpayCheckoutTest extends NativePaymentTestCase
{
    protected string $publicationCurrency = 'CNY';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://core.example.test']);
        $this->payment->update(['payment' => 'EPay', 'config' => [
            'url' => 'https://epay.example.test/gateway', 'pid' => '42', 'key' => 'original-merchant-secret', 'type' => 'alipay',
        ]]);
        Http::preventStrayRequests();
    }

    public function test_real_frozen_purchase_dispatch_and_signed_notify_settle_atomically_and_replay(): void
    {
        [$user, $order] = $this->purchase();
        $checkout = app(NativePaymentCheckout::class);
        $started = $checkout->start($user->id, $order->id, $this->payment->id, 'epay-purchase');
        $this->assertSame('CNY', $started['attempt']['currency']);
        $this->assertSame('1234', $started['attempt']['amount_minor']);
        $dispatch = DB::table('tx_payment_dispatch')->first();
        $this->assertStringNotContainsString('original-merchant-secret', $dispatch->snapshot_ciphertext);
        $this->assertStringNotContainsString('original-merchant-secret', json_encode($started));
        $params = $this->notification($dispatch->attempt_id);
        $result = $checkout->notify('EPay', $this->payment->uuid, $params);
        $this->assertSame($result, $checkout->notify('EPay', $this->payment->uuid, $params));
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
        $this->assertSame(2, DB::table('tx_money_posting')->count());
        $this->assertSame('succeeded', DB::table('tx_payment_attempt')->value('status'));
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertSame(0, DB::table('tx_subscription')->count());
        $this->assertSame(0, (int) $user->fresh()->balance);
        Http::assertNothingSent();
    }

    public function test_replay_and_late_notify_retain_original_key_uuid_and_gateway_after_registration_changes(): void
    {
        [$user, $order] = $this->purchase();
        $checkout = app(NativePaymentCheckout::class);
        $originalUuid = $this->payment->uuid;
        $started = $checkout->start($user->id, $order->id, $this->payment->id, 'frozen-replay');
        $params = $this->notification($started['attempt']['attempt_id']);
        $this->payment->update(['enable' => false, 'uuid' => bin2hex(random_bytes(16)),
            'config' => ['url' => 'https://changed.example.test', 'pid' => '99', 'key' => 'changed-key']]);
        app(PublishedOrderService::class)->transitionOrder($order->id,
            static fn (Order $locked) => $locked->update(['status' => Order::STATUS_CANCELLED]));
        $this->rejects('PAYMENT_PROVIDER_UNAVAILABLE',
            fn () => $checkout->start($user->id, $order->id, $this->payment->id, 'frozen-replay'));
        $this->assertTrue($checkout->recognizes('EPay', $originalUuid, $params));
        $checkout->notify('EPay', $originalUuid, $params);
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
        $this->assertSame('1234', (string) DB::table('tx_money_account')->where('purpose', 'refund_liability')->value('balance_minor'));
        $this->assertSame(0, DB::table('tx_subscription')->count());
    }

    public function test_signed_mismatches_and_bad_callback_paths_cannot_write_receipts_or_fall_through(): void
    {
        [$user, $order] = $this->purchase();
        $checkout = app(NativePaymentCheckout::class);
        $started = $checkout->start($user->id, $order->id, $this->payment->id, 'bad-notification');
        $params = $this->notification($started['attempt']['attempt_id']);
        $this->assertTrue($checkout->recognizes('other', 'wrong-uuid', $params));
        $this->rejects('NATIVE_PAYMENT_CALLBACK_BINDING_INVALID',
            fn () => $checkout->notify('EPay', 'wrong-uuid', $params));
        foreach ([['money' => '12.35'], ['pid' => '43']] as $override) {
            $changed = array_replace($params, $override);
            $changed['sign'] = $this->sign($changed);
            $this->rejects('NATIVE_EPAY_FACT_BINDING_INVALID',
                fn () => $checkout->notify('EPay', $this->payment->uuid, $changed));
        }
        $changed = array_replace($params, ['unsigned_extra' => 'tampered']);
        $this->rejects('NATIVE_EPAY_SIGNATURE_INVALID',
            fn () => $checkout->notify('EPay', $this->payment->uuid, $changed));
        $this->assertSame(0, DB::table('tx_payment_receipt')->count());
        $this->assertSame(0, DB::table('tx_money_journal')->count());
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
    }

    public function test_query_timeout_mismatch_and_verified_recovery_use_frozen_config_outside_transactions(): void
    {
        [$user, $order] = $this->purchase();
        [$other] = $this->purchase();
        $checkout = app(NativePaymentCheckout::class);
        $started = $checkout->start($user->id, $order->id, $this->payment->id, 'query-recovery');
        $attemptId = (int) $started['attempt']['attempt_id'];
        $params = $this->notification($attemptId);
        $this->rejects('PAYMENT_ATTEMPT_MISSING', fn () => $checkout->query($other->id, $attemptId));
        $query = ['code' => 1, 'pid' => '42', 'status' => 1, 'out_trade_no' => $params['out_trade_no'],
            'trade_no' => $params['trade_no'], 'money' => '12.35'];
        $sequence = 0;
        Http::fake(function ($request) use (&$sequence, &$query) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('https://epay.example.test/gateway/api.php', $request->url());
            $this->assertSame('original-merchant-secret', $request['key']);
            if (++$sequence === 1) {
                throw new ConnectionException('timeout');
            }
            return Http::response($query);
        });
        $this->assertSame('unknown', $checkout->query($user->id, $attemptId)['status']);
        $this->assertSame('unknown', $checkout->query($user->id, $attemptId)['status']);
        $this->assertSame(0, DB::table('tx_payment_receipt')->count());
        $this->payment->update(['enable' => false, 'config' => ['url' => 'https://changed.example.test', 'pid' => '9', 'key' => 'changed']]);
        $query['money'] = '12.34';
        $this->assertSame('succeeded', $checkout->recover($user->id, $attemptId)['status']);
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
    }

    public function test_fees_and_tampered_encrypted_evidence_are_rejected_with_no_partial_dispatch(): void
    {
        [$user, $order] = $this->purchase();
        $checkout = app(NativePaymentCheckout::class);
        $this->payment->update(['handling_fee_fixed' => 1]);
        Sanctum::actingAs($user);
        $this->getJson('/txapi/billing/payment-methods')->assertOk()->assertJsonCount(0, 'data');
        $this->rejects('NATIVE_PAYMENT_FEES_UNSUPPORTED',
            fn () => $checkout->start($user->id, $order->id, $this->payment->id, 'fees'));
        $this->assertSame(0, DB::table('tx_payment_attempt')->count());
        $this->assertSame(0, DB::table('tx_payment_dispatch')->count());
        $this->payment->update(['handling_fee_fixed' => 0]);
        $this->getJson('/txapi/billing/payment-methods')->assertOk()->assertJsonCount(1, 'data');
        $checkout->start($user->id, $order->id, $this->payment->id, 'fees');
        DB::table('tx_payment_dispatch')->update(['snapshot_ciphertext' => 'corrupt']);
        $this->rejects('NATIVE_PAYMENT_DISPATCH_EVIDENCE_INVALID',
            fn () => $checkout->start($user->id, $order->id, $this->payment->id, 'fees'));
        $this->assertSame(1, DB::table('tx_payment_attempt')->count());
        $this->assertSame(0, DB::table('tx_payment_receipt')->count());
    }

    public function test_real_user_http_purchase_callback_query_and_durable_fulfillment_use_one_financial_authority(): void
    {
        [, $user] = app(NativeIdentityService::class)->create('paid-http@example.test', 'runtime-password');
        Sanctum::actingAs($user);
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        $this->getJson('/txapi/plans')->assertOk();
        $this->postJson('/txapi/orders', ['offer_id' => $offer['offer_id']], ['Idempotency-Key' => 'paid-http-order'])
            ->assertCreated();
        $order = Order::query()->where('user_id', $user->id)->firstOrFail();
        $this->postJson('/txapi/orders/'.$order->trade_no.'/checkout', ['method' => $this->payment->id])
            ->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_IDEMPOTENCY_KEY_REQUIRED');
        $response = $this->postJson('/txapi/orders/'.$order->trade_no.'/checkout',
            ['method' => $this->payment->id], ['Idempotency-Key' => 'paid-http-attempt'])
            ->assertOk()->assertJsonPath('data.attempt.amount_minor', '1234');
        $attemptId = $response->json('data.attempt.attempt_id');
        $params = $this->notification($attemptId);
        $this->post('/txapi/payment/webhook/EPay/'.$this->payment->uuid, $params)->assertOk()->assertSeeText('success');
        $this->getJson('/txapi/billing/payment-attempts/'.$attemptId)
            ->assertOk()->assertJsonPath('data.status', 'succeeded')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('completed', array_values(app(NativeBillingRecovery::class)->recover())[0]['status']);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(1, DB::table('tx_subscription_operation')->where('order_id', $order->id)->count());
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
        $this->assertSame(0, (int) $user->fresh()->balance);
        [, $outsider] = app(NativeIdentityService::class)->create('paid-outsider@example.test', 'runtime-password');
        Sanctum::actingAs($outsider);
        $this->postJson('/txapi/billing/payment-attempts/'.$attemptId.'/query')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_paid_status_or_legacy_paid_transition_cannot_bypass_receipt_and_ledger_evidence(): void
    {
        [$user, $order] = $this->purchase();
        $this->assertFalse((new NativeOrderLifecycle($order))->paid('claimed-without-receipt'));
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        DB::table('tx_order')->where('id', $order->id)->update(['status' => Order::STATUS_PROCESSING]);
        $this->rejects('PAYMENT_ORDER_ALLOCATION_REQUIRED',
            fn () => app(SubscriptionFulfillmentService::class)->fulfill($order->id));
        $this->assertSame(0, DB::table('tx_subscription')->where('user_id', $user->id)->count());
    }

    public function test_mixed_wallet_channel_payment_captures_full_price_once_and_extra_success_only_creates_channel_liability(): void
    {
        [$user, $order] = $this->purchase();
        $this->creditWallet($user, '1000');
        $checkout = app(NativePaymentCheckout::class);
        $first = $checkout->start($user->id, $order->id, $this->payment->id, 'mixed-first', '500');
        $second = $checkout->start($user->id, $order->id, $this->payment->id, 'mixed-second');
        $this->assertSame('734', $first['attempt']['amount_minor']);
        $this->assertSame('734', $second['attempt']['amount_minor']);
        $winner = $checkout->notify('EPay', $this->payment->uuid, $this->notification($first['attempt']['attempt_id']));
        $extra = $checkout->notify('EPay', $this->payment->uuid, $this->notification($second['attempt']['attempt_id']));
        $this->assertSame('order_payment', $winner['decision']);
        $this->assertSame('refund_liability', $extra['decision']);
        $this->assertSame('734', $extra['amount_minor']);
        $this->assertSame('1234', (string) DB::table('tx_order_payment')->value('amount_minor'));
        $this->assertSame('1234', (string) DB::table('tx_money_account')->where('purpose', 'sales_revenue')->value('balance_minor'));
        $this->assertSame('734', (string) DB::table('tx_money_account')->where('purpose', 'refund_liability')->value('balance_minor'));
        $this->assertSame('500', (string) DB::table('tx_money_account')->where('owner_id', $user->id)
            ->where('purpose', 'wallet_available')->value('balance_minor'));
        $this->assertSame('0', (string) DB::table('tx_money_account')->where('owner_id', $user->id)
            ->where('purpose', 'wallet_reserved')->value('balance_minor'));
        $this->assertSame('completed', array_values(app(NativeBillingRecovery::class)->recover())[0]['status']);
        $this->assertSame(1, DB::table('tx_subscription')->where('user_id', $user->id)->count());
    }

    public function test_cancel_releases_only_reserved_wallet_and_late_channel_success_records_the_exact_channel_liability(): void
    {
        [$user, $order] = $this->purchase();
        $this->creditWallet($user, '1000');
        $checkout = app(NativePaymentCheckout::class);
        $started = $checkout->start($user->id, $order->id, $this->payment->id, 'mixed-cancel', '500');
        $this->assertTrue((new NativeOrderLifecycle($order))->cancel());
        $result = $checkout->notify('EPay', $this->payment->uuid, $this->notification($started['attempt']['attempt_id']));
        $this->assertSame('refund_liability', $result['decision']);
        $this->assertSame('734', $result['amount_minor']);
        $this->assertSame('1000', (string) DB::table('tx_money_account')->where('owner_id', $user->id)
            ->where('purpose', 'wallet_available')->value('balance_minor'));
        $this->assertSame(0, DB::table('tx_order_payment')->count());
        $this->assertSame(0, DB::table('tx_billing_work')->count());
    }

    public function test_purchase_and_recharge_cannot_spend_the_same_external_provider_transaction(): void
    {
        [$user, $order] = $this->purchase();
        $rechargeAttempt = $this->creditWallet($user, '1234');
        $checkout = app(NativePaymentCheckout::class);
        $started = $checkout->start($user->id, $order->id, $this->payment->id, 'cross-intent');
        $params = $this->notification($started['attempt']['attempt_id']);
        $params['trade_no'] = 'channel-tx-'.$rechargeAttempt;
        $params['sign'] = $this->sign($params);
        $this->rejects('PAYMENT_RECEIPT_IDENTITY_CONFLICT',
            fn () => $checkout->notify('EPay', $this->payment->uuid, $params));
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
    }

    public function test_worker_queries_unknown_original_attempts_without_sql_locks_and_rate_limits_repeated_uncertainty(): void
    {
        [$user, $order] = $this->purchase();
        $started = app(NativePaymentCheckout::class)->start($user->id, $order->id, $this->payment->id, 'worker-query');
        $reference = DB::table('tx_payment_dispatch')->where('attempt_id', $started['attempt']['attempt_id'])
            ->value('provider_order_ref');
        $queries = [];
        Http::fake(static function ($request) use (&$queries) {
            $queries[] = ['transaction_level' => DB::transactionLevel(), 'method' => $request->method(),
                'url' => $request->url(), 'act' => $request['act'], 'out_trade_no' => $request['out_trade_no'],
                'pid' => $request['pid'], 'key' => $request['key']];
            throw new ConnectionException('provider unavailable');
        });
        $result = app(NativeBillingRecovery::class)->recoverPayments();
        $this->assertSame('unknown', $result[$started['attempt']['attempt_id']]['status']);
        $this->assertSame([], app(NativeBillingRecovery::class)->recoverPayments());
        // A throwing fake has no response for Laravel's recorded request/response pairs.
        $this->assertSame([['transaction_level' => 0, 'method' => 'POST',
            'url' => 'https://epay.example.test/gateway/api.php', 'act' => 'order', 'out_trade_no' => $reference,
            'pid' => '42', 'key' => 'original-merchant-secret']], $queries);
        $this->assertSame('unknown', DB::table('tx_payment_attempt')->value('status'));
        $this->assertSame(0, DB::table('tx_payment_receipt')->count());
        $this->assertSame(0, DB::table('tx_money_journal')->count());
    }

    private function creditWallet(User $user, string $amount): string
    {
        $service = app(NativeRechargeService::class);
        $recharge = $service->create($user->id, $amount, $this->payment->id, 'wallet-credit-'.bin2hex(random_bytes(6)));
        $started = $service->checkout($user->id, $recharge['trade_no']);
        $attemptId = $started['attempt']['attempt_id'];
        app(NativePaymentCheckout::class)->notify('EPay', $this->payment->uuid, $this->notification($attemptId));
        return $attemptId;
    }

    private function notification(int|string $attemptId): array
    {
        $ciphertext = DB::table('tx_payment_dispatch')->where('attempt_id', $attemptId)->value('snapshot_ciphertext');
        $snapshot = json_decode(Crypt::decryptString($ciphertext), true, 16, JSON_THROW_ON_ERROR);
        $params = ['pid' => $snapshot['pid'], 'out_trade_no' => $snapshot['provider_order_ref'],
            'trade_no' => 'channel-tx-'.$attemptId,
            'money' => app(\Plugin\Epay\NativeAdapter::class)->decimal($snapshot['amount_minor']),
            'trade_status' => 'TRADE_SUCCESS', 'sign_type' => 'MD5'];
        return $params + ['sign' => $this->sign($params)];
    }

    private function sign(array $params): string
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params, SORT_STRING);
        return md5(stripslashes(urldecode(http_build_query($params))).'original-merchant-secret');
    }
}
