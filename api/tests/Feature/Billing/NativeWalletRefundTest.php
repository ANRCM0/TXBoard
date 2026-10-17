<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\NativeBillingReconciliation;
use App\Domains\Billing\NativeBillingRecovery;
use App\Domains\Billing\NativeBillingTransaction;
use App\Domains\Billing\MoneyValue;
use App\Domains\Billing\NativeMoneyLedger;
use App\Domains\Billing\NativeOrderLifecycle;
use App\Domains\Billing\NativePaymentAttempts;
use App\Domains\Billing\NativePaymentSettlement;
use App\Domains\Billing\NativeRechargeService;
use App\Domains\Billing\NativeRefundService;
use App\Domains\Billing\NativeWalletPayments;
use App\Domains\Billing\NativeWalletReservations;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use App\Models\User;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\NativePaymentTestCase;

final class NativeWalletRefundTest extends NativePaymentTestCase
{
    protected string $publicationCurrency = 'CNY';
    protected array $planPrices = ['monthly' => '12.34', 'onetime' => '0.00', 'reset_traffic' => '2.00'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->payment->update(['payment' => 'EPay', 'config' => [
            'url' => 'https://epay.example.test', 'pid' => '42', 'key' => 'test-secret', 'type' => 'alipay',
        ]]);
    }

    public function test_wallet_uses_real_receipt_funds_not_user_balance_and_creates_no_fake_channel_receipt(): void
    {
        [$user, $order] = $this->purchase();
        DB::table('tx_user')->where('id', $user->id)->update(['balance' => 50000]);
        $wallet = app(NativeWalletPayments::class);
        $this->rejects('BILLING_INSUFFICIENT_FUNDS', fn () => $wallet->pay($user->id, $order->id, 'wallet-real-funds'));
        $this->assertSame(0, DB::table('tx_wallet_order_reservation')->count());
        $this->fund($user);
        $payment = $wallet->pay($user->id, $order->id, 'wallet-real-funds');
        $this->assertSame($payment, $wallet->pay($user->id, $order->id, 'wallet-real-funds'));
        $this->assertSame('1234', $payment['payment']['amount_minor']);
        $this->assertNull($payment['payment']['receipt_id']);
        $this->assertNull($payment['payment']['settlement_id']);
        $this->assertSame('3766', $this->walletBalance($user, 'wallet_available'));
        $this->assertSame('0', $this->walletBalance($user, 'wallet_reserved'));
        $this->assertSame(50000, (int) $user->fresh()->balance);
        $this->assertSame(1, DB::table('tx_payment_attempt')->count(), 'Only the real recharge has a channel attempt.');
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame(1, DB::table('tx_order_payment')->count());
        $this->assertSame(1, DB::table('tx_billing_work')->where('status', 'pending')->count());
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->rejects('WALLET_IDEMPOTENCY_CONFLICT', fn () => $wallet->pay($user->id, $order->id, 'another-key'));
        $result = app(NativeBillingRecovery::class)->recover();
        $this->assertSame('completed', $result[$payment['payment']['work_id']]['status']);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
    }

    public function test_reservation_and_unpaid_cancel_release_native_funds_atomically(): void
    {
        [$user, $order] = $this->purchase();
        $this->fund($user);
        $this->reserve($user, $order, '500', 'reserved-order');
        $this->assertSame('4500', $this->walletBalance($user, 'wallet_available'));
        $this->assertSame('500', $this->walletBalance($user, 'wallet_reserved'));
        $this->assertTrue((new NativeOrderLifecycle($order))->cancel());
        $this->assertSame('5000', $this->walletBalance($user, 'wallet_available'));
        $this->assertSame('0', $this->walletBalance($user, 'wallet_reserved'));
        $this->assertSame('released', DB::table('tx_wallet_order_reservation')->value('status'));
        $this->assertSame(0, DB::table('tx_order_payment')->count());
        $this->assertFalse((new NativeOrderLifecycle($order->fresh()))->cancel());
        $this->assertSame(3, DB::table('tx_money_journal')->count());
    }

    public function test_even_a_failed_channel_attempt_prevents_reallocating_its_frozen_channel_amount(): void
    {
        [$user, $order] = $this->purchase();
        $this->fund($user);
        $attempts = app(NativePaymentAttempts::class);
        $attempt = $attempts->prepare($user->id, $order->id, $this->payment->id, 'merchant-1', 'failed-channel');
        $attempts->markFailed($user->id, (int) $attempt['attempt_id']);
        $this->rejects('WALLET_CHANNEL_PAYMENT_PENDING', fn () => app(NativeWalletPayments::class)->pay($user->id, $order->id, 'replace-failed'));
        $late = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => 'real-late-channel']));
        $this->assertSame('order_payment', $late['decision']);
        $this->assertSame('5000', $this->walletBalance($user, 'wallet_available'));
    }

    public function test_recharge_shares_receipts_replays_permanently_and_extra_actual_payment_is_liability(): void
    {
        [$user] = $this->purchase();
        $service = app(NativeRechargeService::class);
        $recharge = $service->create($user->id, '2000', $this->payment->id, 'same-recharge');
        $this->assertSame($recharge, $service->create($user->id, '2000', $this->payment->id, 'same-recharge'));
        $this->rejects('RECHARGE_IDEMPOTENCY_CONFLICT', fn () => $service->create($user->id, '2001', $this->payment->id, 'same-recharge'));
        $attempts = app(NativePaymentAttempts::class);
        $first = $attempts->prepareRecharge($user->id, (int) $recharge['recharge_id'], $this->payment->id, 'merchant-1', 'first-recharge-attempt');
        $second = $attempts->prepareRecharge($user->id, (int) $recharge['recharge_id'], $this->payment->id, 'merchant-1', 'second-recharge-attempt');
        $settlement = app(NativePaymentSettlement::class);
        $fact = $this->fact($first, ['transaction_ref' => 'recharge-real-1']);
        $one = $settlement->settle($user->id, (int) $first['attempt_id'], $fact);
        $this->assertSame($one, $settlement->settle($user->id, (int) $first['attempt_id'], $fact));
        $twoFact = $this->fact($second, ['transaction_ref' => 'recharge-real-2']);
        $two = $settlement->settle($user->id, (int) $second['attempt_id'], $twoFact);
        $this->assertSame('wallet_credit', $one['decision']);
        $this->assertSame('refund_liability', $two['decision']);
        $this->assertSame($two, $settlement->settle($user->id, (int) $second['attempt_id'], $twoFact));
        $this->assertSame('2000', $this->walletBalance($user, 'wallet_available'));
        $this->assertSame(2, DB::table('tx_payment_receipt')->count());
        $actor = $this->admin();
        $refunds = app(NativeRefundService::class);
        $this->rejects('REFUND_SOURCE_NOT_REFUNDABLE',
            fn () => $refunds->refundToWallet($user->id, 'receipt', (int) $one['receipt_id'], '100', 'double-credit', $actor->id, 'duplicate'));
        $refunded = $refunds->refundToWallet($user->id, 'receipt', (int) $two['receipt_id'], '2000', 'extra-recharge-return', $actor->id, 'duplicate');
        $this->assertSame('none', $refunded['entitlement_effect']);
        $this->assertSame('4000', $this->walletBalance($user, 'wallet_available'));
    }

    public function test_partial_and_full_channel_refunds_arrive_in_wallet_with_source_limit_and_durable_replay(): void
    {
        [$user, $order, $settlement] = $this->channelPayment();
        $actor = $this->admin();
        $refunds = app(NativeRefundService::class);
        $partial = $refunds->refundToWallet($user->id, 'receipt', (int) $settlement['receipt_id'], '234', 'partial', $actor->id, 'customer_request');
        $this->assertSame('none', $partial['entitlement_effect']);
        $this->assertNull($partial['subscription_id']);
        $this->assertFalse($partial['external_refund']);
        $this->assertSame('wallet_credited', $partial['status']);
        $this->assertSame('234', $this->walletBalance($user, 'wallet_available'));
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $full = $refunds->refundToWallet($user->id, 'receipt', (int) $settlement['receipt_id'], '1000', 'remaining', $actor->id, 'customer_request');
        $this->assertSame('1234', $full['cumulative_minor']);
        $this->assertSame('none', $full['entitlement_effect']);
        $this->assertNull($full['subscription_id']);
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertSame('pending', DB::table('tx_billing_work')->value('status'));
        $this->assertSame($partial, $refunds->refundToWallet($user->id, 'receipt', (int) $settlement['receipt_id'], '234', 'partial', $actor->id, 'customer_request'));
        $this->assertSame($full, $refunds->refundToWallet($user->id, 'receipt', (int) $settlement['receipt_id'], '1000', 'remaining', $actor->id, 'customer_request'));
        $this->assertSame('1234', $this->walletBalance($user, 'wallet_available'));
        $this->rejects('REFUND_AMOUNT_EXCEEDS_SOURCE',
            fn () => $refunds->refundToWallet($user->id, 'receipt', (int) $settlement['receipt_id'], '1', 'over-limit', $actor->id, 'customer_request'));
        $this->rejects('REFUND_IDEMPOTENCY_CONFLICT',
            fn () => $refunds->refundToWallet($user->id, 'receipt', (int) $settlement['receipt_id'], '235', 'partial', $actor->id, 'customer_request'));
        $this->rejects('REFUND_EXTERNAL_UNSUPPORTED', fn () => $refunds->refundExternally());
        $this->assertSame('completed', array_values(app(NativeBillingRecovery::class)->recover())[0]['status']);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame('active', DB::table('tx_subscription')->value('status'));
        $this->assertSame('1234', $this->walletBalance($user, 'wallet_available'));
    }

    public function test_wallet_payment_refund_references_real_capture_without_fabricating_a_receipt(): void
    {
        [$user, $order] = $this->purchase();
        $this->fund($user);
        $payment = app(NativeWalletPayments::class)->pay($user->id, $order->id, 'wallet-refund-source')['payment'];
        $actor = $this->admin();
        $result = app(NativeRefundService::class)->refundToWallet($user->id, 'wallet_payment',
            (int) $payment['order_payment_id'], '1234', 'wallet-full-return', $actor->id, 'customer_request');
        $this->assertNull($result['receipt_id']);
        $this->assertSame($payment['journal_id'], $result['source_journal_id']);
        $this->assertSame('none', $result['entitlement_effect']);
        $this->assertSame('5000', $this->walletBalance($user, 'wallet_available'));
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $replay = app(NativeWalletPayments::class)->pay($user->id, $order->id, 'wallet-refund-source');
        $this->assertSame($payment, $replay['payment'], 'Refunding money does not erase the original successful payment result.');
        $this->assertSame('5000', $this->walletBalance($user, 'wallet_available'));
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertSame('pending', DB::table('tx_billing_work')->value('status'));
    }

    public function test_mixed_refund_components_share_one_order_limit_without_changing_fulfillment(): void
    {
        [$user, $order] = $this->purchase();
        $this->fund($user);
        $this->reserve($user, $order, '500', 'mixed-allocation');
        $attempt = app(NativePaymentAttempts::class)->prepare($user->id, $order->id, $this->payment->id, 'merchant-1', 'mixed-channel');
        $this->assertSame('734', $attempt['amount_minor']);
        $settled = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => 'mixed-real-channel']));
        $actor = $this->admin();
        $service = app(NativeRefundService::class);
        $channel = $service->refundToWallet($user->id, 'receipt', (int) $settled['receipt_id'], '734', 'return-channel', $actor->id, 'customer_request');
        $this->assertSame('none', $channel['entitlement_effect']);
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $wallet = $service->refundToWallet($user->id, 'wallet_payment', (int) $settled['order_payment_id'],
            '500', 'return-wallet', $actor->id, 'customer_request');
        $this->assertSame('none', $wallet['entitlement_effect']);
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertSame('pending', DB::table('tx_billing_work')->value('status'));
        $this->assertSame('5734', $this->walletBalance($user, 'wallet_available'), 'The original top-up plus real channel proceeds return once.');
        $this->rejects('REFUND_AMOUNT_EXCEEDS_SOURCE',
            fn () => $service->refundToWallet($user->id, 'wallet_payment', (int) $settled['order_payment_id'], '1',
                'wallet-over', $actor->id, 'customer_request'));
    }

    public function test_full_refund_retains_subscription_usage_credentials_and_pending_fulfillment(): void
    {
        [$user, $order, $settlement] = $this->channelPayment();
        $fulfilled = app(SubscriptionFulfillmentService::class)->fulfill($order->id, time() - 5);
        $this->assertSame('pending', DB::table('tx_billing_work')->value('status'));
        $period = DB::table('tx_usage_period')->where('id', $fulfilled['usage_period_id'])->first();
        DB::table('tx_usage_period')->where('id', $period->id)->update(['upload_bytes' => '10', 'download_bytes' => '20', 'billable_bytes' => '30']);
        $tables = ['tx_payment_attempt', 'tx_payment_receipt', 'tx_subscription', 'tx_subscription_slot', 'tx_subscription_terms', 'tx_usage_period',
            'tx_subscription_operation', 'tx_native_runtime_outbox', 'tx_billing_work'];
        $before = $this->tableSnapshots($tables);
        $actor = $this->admin();
        $service = app(NativeRefundService::class);
        $result = $service->refundToWallet($user->id, 'receipt', (int) $settlement['receipt_id'], '1234', 'fulfilled-full', $actor->id, 'customer_request');
        $this->assertSame('none', $result['entitlement_effect']);
        $this->assertNull($result['subscription_id']);
        $this->assertSame($before, $this->tableSnapshots($tables));
        $this->assertSame('1234', $this->walletBalance($user, 'wallet_available'));
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame($result, $service->refundToWallet($user->id, 'receipt', (int) $settlement['receipt_id'], '1234', 'fulfilled-full', $actor->id, 'customer_request'));
        $this->assertSame($before, $this->tableSnapshots($tables));
        $this->assertSame('completed', array_values(app(NativeBillingRecovery::class)->recover())[0]['status']);
        $this->assertSame('active', DB::table('tx_subscription')->value('status'));
        $this->assertSame('30', (string) DB::table('tx_usage_period')->where('id', $period->id)->value('billable_bytes'));
        $this->assertSame(0, DB::table('tx_subscription_operation')->where('kind', 'revoke')->count());
    }

    public function test_full_refund_preserves_acknowledged_fulfillment_work_as_completed_history(): void
    {
        [$user, $order, $settlement] = $this->channelPayment();
        app(SubscriptionFulfillmentService::class)->fulfill($order->id, time() - 5);
        $this->assertSame('completed', array_values(app(NativeBillingRecovery::class)->recover())[0]['status']);
        $work = DB::table('tx_billing_work')->first();
        $actor = $this->admin();
        $refund = app(NativeRefundService::class)->refundToWallet($user->id, 'receipt',
            (int) $settlement['receipt_id'], '1234', 'acknowledged-full', $actor->id, 'customer_request');
        $this->assertSame('none', $refund['entitlement_effect']);
        $this->assertSame('active', DB::table('tx_subscription')->value('status'));
        $retained = DB::table('tx_billing_work')->where('id', $work->id)->first();
        $this->assertSame('completed', $retained->status);
        $this->assertSame((string) $work->completed_at, (string) $retained->completed_at);
        $this->assertSame((int) $work->attempts, (int) $retained->attempts);
        $this->assertSame([], app(NativeBillingRecovery::class)->recover());
    }

    public function test_superseded_purchase_full_refund_succeeds_without_rewriting_later_renewal(): void
    {
        [$user, $order, $settled] = $this->channelPayment();
        $first = app(SubscriptionFulfillmentService::class)->fulfill($order->id, time() - 10);
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        $renewal = app(PublishedOrderService::class)->create($user->id, (int) $offer['offer_id'], 'later-renewal');
        $attempt = app(NativePaymentAttempts::class)->prepare($user->id, $renewal->id, $this->payment->id, 'merchant-1', 'renewal-channel');
        app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => 'renewal-real-channel']));
        app(SubscriptionFulfillmentService::class)->fulfill($renewal->id, time() - 5);
        $before = $this->tableSnapshots(['tx_subscription', 'tx_subscription_slot', 'tx_subscription_terms',
            'tx_usage_period', 'tx_subscription_operation', 'tx_billing_work']);
        $actor = $this->admin();
        $result = app(NativeRefundService::class)->refundToWallet($user->id, 'receipt',
            (int) $settled['receipt_id'], '1234', 'old-full', $actor->id, 'customer_request');
        $this->assertSame('none', $result['entitlement_effect']);
        $this->assertSame('active', DB::table('tx_subscription')->where('id', $first['subscription_id'])->value('status'));
        $this->assertSame(1, DB::table('tx_native_refund')->count());
        $this->assertSame('1234', $this->walletBalance($user, 'wallet_available'));
        $this->assertSame($before, $this->tableSnapshots(array_keys($before)));
    }

    public function test_refund_write_failure_rolls_back_credit_and_all_evidence_without_touching_fulfillment(): void
    {
        [$user, $order, $settled] = $this->channelPayment();
        $actor = $this->admin();
        $inject = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$inject): void {
            if ($inject && (str_contains(strtolower($sql), 'insert into "tx_native_refund"')
                || str_contains(strtolower($sql), 'insert into `tx_native_refund`'))) {
                $inject = false;
                throw new \RuntimeException('INJECTED_REFUND_EVIDENCE_FAILURE');
            }
        });
        try {
            app(NativeRefundService::class)->refundToWallet($user->id, 'receipt', (int) $settled['receipt_id'],
                '1234', 'atomic-refund', $actor->id, 'customer_request');
            $this->fail('Injected refund persistence failure must roll back every effect.');
        } catch (\RuntimeException $error) {
            $this->assertSame('INJECTED_REFUND_EVIDENCE_FAILURE', $error->getMessage());
        }
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertSame('pending', DB::table('tx_billing_work')->value('status'));
        $this->assertSame(0, DB::table('tx_native_refund')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
        $this->assertSame('0', $this->walletBalance($user, 'wallet_available'));
    }

    public function test_partial_then_full_refunds_of_fulfilled_renewal_and_reset_only_credit_wallet(): void
    {
        foreach (['monthly', 'reset_traffic'] as $period) {
            [$user, $original] = $this->channelPayment();
            $subscription = app(SubscriptionFulfillmentService::class)->fulfill($original->id, time() - 10);
            $offer = collect($this->offers)->firstWhere('offer_code', $period);
            $later = app(PublishedOrderService::class)->create($user->id, (int) $offer['offer_id'], 'later-'.$period);
            $attempt = app(NativePaymentAttempts::class)->prepare($user->id, $later->id,
                $this->payment->id, 'merchant-1', 'later-payment:'.$period);
            $settled = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
                $this->fact($attempt, ['transaction_ref' => 'later-real:'.$period]));
            app(SubscriptionFulfillmentService::class)->fulfill($later->id, time() - 5);
            $before = $this->tableSnapshots(['tx_subscription', 'tx_subscription_slot', 'tx_subscription_terms',
                'tx_usage_period', 'tx_subscription_operation', 'tx_native_runtime_outbox', 'tx_billing_work']);
            $actor = $this->admin();
            $service = app(NativeRefundService::class);
            $amount = $settled['amount_minor'];
            $partialAmount = \App\Domains\Billing\MoneyValue::add($amount, '-1');
            $partial = $service->refundToWallet($user->id, 'receipt', (int) $settled['receipt_id'],
                $partialAmount, 'partial:'.$period, $actor->id, 'customer_request');
            $this->assertSame('none', $partial['entitlement_effect']);
            $this->assertSame($partialAmount, $this->walletBalance($user, 'wallet_available'));
            $full = $service->refundToWallet($user->id, 'receipt', (int) $settled['receipt_id'],
                '1', 'remaining:'.$period, $actor->id, 'customer_request');
            $this->assertSame('none', $full['entitlement_effect']);
            $this->assertSame($amount, $full['cumulative_minor']);
            $this->assertSame($amount, $this->walletBalance($user, 'wallet_available'));
            $this->assertSame($before, $this->tableSnapshots(array_keys($before)));
            $this->assertSame('active', DB::table('tx_subscription')->where('id', $subscription['subscription_id'])->value('status'));
            $this->assertSame(0, DB::table('tx_subscription_operation')->where('user_id', $user->id)->where('kind', 'revoke')->count());
        }
    }

    public function test_cross_user_and_unprivileged_refunds_fail_without_money_changes(): void
    {
        [$user, , $settled] = $this->channelPayment();
        [$other] = $this->purchase();
        $admin = $this->admin();
        $service = app(NativeRefundService::class);
        $this->rejects('REFUND_SOURCE_MISSING', fn () => $service->refundToWallet($other->id, 'receipt',
            (int) $settled['receipt_id'], '1', 'wrong-owner', $admin->id, 'customer_request'));
        $this->rejects('REFUND_ACTOR_FORBIDDEN', fn () => $service->refundToWallet($user->id, 'receipt',
            (int) $settled['receipt_id'], '1', 'not-admin', $other->id, 'customer_request'));
        $this->assertSame(0, DB::table('tx_native_refund')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
    }

    public function test_other_mixed_source_refund_journal_is_verified_before_another_wallet_credit(): void
    {
        [$user, $order] = $this->purchase();
        $this->fund($user);
        $this->reserve($user, $order, '500', 'mixed-corrupt-source');
        $attempt = app(NativePaymentAttempts::class)->prepare($user->id, $order->id,
            $this->payment->id, 'merchant-1', 'mixed-corrupt-channel');
        $paid = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => 'mixed-corrupt-payment']));
        $actor = $this->admin();
        $refunds = app(NativeRefundService::class);
        $channel = $refunds->refundToWallet($user->id, 'receipt', (int) $paid['receipt_id'],
            '734', 'mixed-channel-return', $actor->id, 'customer_request');
        DB::table('tx_money_journal')->where('id', $channel['journal_id'])->update(['request_hash' => str_repeat('0', 64)]);
        $this->rejects('BILLING_EVIDENCE_CORRUPT', fn () => $refunds->refundToWallet($user->id, 'wallet_payment',
            (int) $paid['order_payment_id'], '500', 'mixed-wallet-return', $actor->id, 'customer_request'));
        $this->assertSame('5234', $this->walletBalance($user, 'wallet_available'));
        $this->assertSame(1, DB::table('tx_native_refund')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->where('business_type', 'refund')->count());
        $this->assertSame('pending', DB::table('tx_billing_work')->value('status'));
    }

    public function test_rehashed_refund_cannot_claim_an_entitlement_effect_or_skip_cumulative_evidence(): void
    {
        [$user, , $paid] = $this->channelPayment();
        $actor = $this->admin();
        $refunds = app(NativeRefundService::class);
        $first = $refunds->refundToWallet($user->id, 'receipt', (int) $paid['receipt_id'],
            '200', 'unchanged-entitlement', $actor->id, 'customer_request');
        $payload = (array) DB::table('tx_native_refund')->where('id', $first['refund_id'])->first();
        unset($payload['id'], $payload['key_hash'], $payload['content_hash']);
        $payload = array_map(static fn ($value) => $value === null ? null : (string) $value, $payload);
        $payload['entitlement_effect'] = 'subscription_revoked';
        DB::table('tx_native_refund')->where('id', $first['refund_id'])->update([
            'entitlement_effect' => $payload['entitlement_effect'], 'content_hash' => MoneyValue::hash($payload),
        ]);
        $this->rejects('REFUND_EVIDENCE_INVALID', fn () => $refunds->refundToWallet($user->id, 'receipt',
            (int) $paid['receipt_id'], '200', 'unchanged-entitlement', $actor->id, 'customer_request'));
        $payload['entitlement_effect'] = 'none';
        $payload['cumulative_minor'] = '201';
        DB::table('tx_native_refund')->where('id', $first['refund_id'])->update([
            'entitlement_effect' => 'none', 'cumulative_minor' => '201', 'content_hash' => MoneyValue::hash($payload),
        ]);
        $this->rejects('REFUND_EVIDENCE_INVALID', fn () => $refunds->refundToWallet($user->id, 'receipt',
            (int) $paid['receipt_id'], '1', 'next-after-corrupt', $actor->id, 'customer_request'));
        $this->assertSame('200', $this->walletBalance($user, 'wallet_available'));
        $this->assertSame(1, DB::table('tx_native_refund')->count());
    }

    public function test_admin_wallet_refund_http_requires_live_permission_and_keeps_entitlements_unchanged(): void
    {
        [$user, $order, $paid] = $this->channelPayment();
        app(SubscriptionFulfillmentService::class)->fulfill($order->id, time() - 5);
        admin_setting(['secure_path' => 'wallet-refund-admin']);
        $path = '/txapi/admin/wallet-refund-admin/native/billing/refunds';
        $body = ['user_id' => (string) $user->id, 'source_type' => 'receipt',
            'source_id' => $paid['receipt_id'], 'amount_minor' => '234', 'reason' => 'customer_request'];
        $headers = ['Idempotency-Key' => 'admin-partial-wallet-refund'];
        $this->postJson($path, $body, $headers)->assertForbidden();
        Sanctum::actingAs($user, ['billing.refund']);
        $this->postJson($path, $body, $headers)->assertForbidden();
        $actor = $this->admin()->fresh();
        Sanctum::actingAs($actor, ['billing.refund']);
        $this->postJson($path, $body, $headers)->assertForbidden();
        DB::table('tx_internal_actor_grant')->insert([
            'user_id' => $actor->id, 'permission' => 'billing.refund', 'created_at' => time(),
        ]);
        Sanctum::actingAs($actor, ['billing.reconcile']);
        $this->postJson($path, $body, $headers)->assertForbidden();
        Sanctum::actingAs($actor, ['billing.refund']);
        $this->postJson('/txapi/admin/wrong/native/billing/refunds', $body, $headers)->assertNotFound();
        foreach (['destination' => 'external', 'entitlement_effect' => 'subscription_revoked'] as $key => $value) {
            $this->postJson($path, $body + [$key => $value], $headers)->assertUnprocessable()
                ->assertJsonPath('error.code', 'BILLING_REQUEST_INVALID');
        }
        $this->assertSame(0, DB::table('tx_native_refund')->count());
        $tables = ['tx_order', 'tx_order_purchase_terms', 'tx_payment_attempt', 'tx_payment_receipt', 'tx_subscription', 'tx_subscription_slot',
            'tx_subscription_terms', 'tx_usage_period', 'tx_subscription_operation', 'tx_native_runtime_outbox', 'tx_billing_work'];
        $before = $this->tableSnapshots($tables);
        $partial = $this->postJson($path, $body, $headers)->assertOk()
            ->assertJsonPath('data.status', 'wallet_credited')->assertJsonPath('data.destination', 'wallet')
            ->assertJsonPath('data.entitlement_effect', 'none')->assertJsonPath('data.subscription_id', null)
            ->assertJsonPath('data.external_refund', false)->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->postJson($path, $body, $headers)->assertOk()->assertJsonPath('data', $partial);
        $this->postJson($path, array_replace($body, ['amount_minor' => '1000']),
            ['Idempotency-Key' => 'admin-remaining-wallet-refund'])->assertOk()
            ->assertJsonPath('data.cumulative_minor', '1234')->assertJsonPath('data.entitlement_effect', 'none');
        $this->assertSame($before, $this->tableSnapshots($tables));
        $this->assertSame('1234', $this->walletBalance($user, 'wallet_available'));
        $this->assertSame(2, DB::table('tx_native_refund')->count());
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->delete();
        $this->postJson($path, $body, $headers)->assertForbidden();
        $this->assertSame('1234', $this->walletBalance($user, 'wallet_available'));
    }

    public function test_reconciliation_is_read_only_bounded_and_reports_projection_corruption(): void
    {
        [$user, $order] = $this->purchase();
        $this->fund($user);
        app(NativeWalletPayments::class)->pay($user->id, $order->id, 'reconcile-wallet');
        $tables = ['tx_money_account', 'tx_money_journal', 'tx_money_posting', 'tx_payment_attempt',
            'tx_payment_receipt', 'tx_native_recharge', 'tx_wallet_order_reservation', 'tx_native_refund', 'tx_order_payment', 'tx_billing_work'];
        $before = [];
        foreach ($tables as $table) { $before[$table] = DB::table($table)->orderBy('id')->get()->toJson(); }
        $service = app(NativeBillingReconciliation::class);
        $report = $service->inspect($user->id);
        $this->assertTrue($report['read_only']);
        $this->assertTrue($report['complete']);
        $this->assertSame([], $report['issues']);
        $this->assertCount(1, $report['pending_work']);
        foreach ($tables as $table) { $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table); }
        $page = $service->inspect($user->id, 1);
        $this->assertFalse($page['complete']);
        $this->assertNotNull($page['next_after_id']);
        DB::table('tx_money_account')->where('owner_id', $user->id)->where('purpose', 'wallet_available')->update(['balance_minor' => 9999]);
        $corrupt = $service->inspect($user->id);
        $this->assertContains('BILLING_ACCOUNT_PROJECTION_MISMATCH', array_column($corrupt['issues'], 'error_code'));
        $this->assertSame('9999', $this->walletBalance($user, 'wallet_available'), 'Reconciliation must not repair projections.');
    }

    public function test_system_projection_reconciliation_uses_every_users_postings_without_exposing_other_wallets(): void
    {
        [$first, $firstOrder] = $this->purchase();
        [$second, $secondOrder] = $this->purchase();
        $this->fund($first);
        $this->fund($second, '7000');
        app(NativeWalletPayments::class)->pay($first->id, $firstOrder->id, 'first-wallet');
        app(NativeWalletPayments::class)->pay($second->id, $secondOrder->id, 'second-wallet');
        $service = app(NativeBillingReconciliation::class);
        $report = $service->inspect($first->id);
        $this->assertTrue($report['complete']);
        $this->assertSame('complete', $report['system_account_projections']);
        $this->assertSame([], $report['issues']);
        $system = collect($report['account_reconciliation']['system']['accounts']);
        $this->assertSame('12000', $system->firstWhere('purpose', 'provider_clearing')['ledger_minor']);
        $this->assertSame('2468', $system->firstWhere('purpose', 'sales_revenue')['ledger_minor']);
        $wallets = $report['account_reconciliation']['wallet']['accounts'];
        $this->assertSame([(string) $first->id], array_values(array_unique(array_column($wallets, 'owner_id'))));
        DB::table('tx_money_account')->where('owner_id', 0)->where('purpose', 'sales_revenue')->update(['balance_minor' => '2469']);
        $corrupt = $service->inspect($first->id);
        $this->assertContains('BILLING_ACCOUNT_PROJECTION_MISMATCH', array_column($corrupt['issues'], 'error_code'));
        $this->assertContains('system_account', array_column($corrupt['issues'], 'kind'));
        $this->assertSame('2469', (string) DB::table('tx_money_account')->where('owner_id', 0)->where('purpose', 'sales_revenue')->value('balance_minor'));
    }

    public function test_exhausted_system_scan_budget_does_not_claim_unvisited_projection_was_checked(): void
    {
        [$user] = $this->purchase();
        $this->fund($user);
        $postings = [];
        for ($position = 0; $position < 32; $position++) {
            $postings[] = ['owner_id' => '0', 'purpose' => 'provider_clearing', 'side' => 'debit', 'amount_minor' => '1'];
            $postings[] = ['owner_id' => '0', 'purpose' => 'provider_clearing', 'side' => 'credit', 'amount_minor' => '1'];
        }
        // Internal balanced zero-net evidence exercises the bounded reader, not a public funding route.
        for ($journal = 0; $journal < 32; $journal++) {
            app(NativeMoneyLedger::class)->post($user->id, [
                'business_type' => 'adjustment', 'business_ref' => 'scan-fixture:'.$journal,
                'idempotency_key' => 'bounded-fixture', 'currency' => 'CNY', 'occurred_at' => (string) time(), 'postings' => $postings,
            ]);
        }
        $report = app(NativeBillingReconciliation::class)->inspect($user->id);
        $this->assertFalse($report['complete']);
        $this->assertSame('incomplete', $report['system_account_projections']);
        $this->assertTrue($report['account_reconciliation']['wallet']['complete'], 'Wallet and global system budgets are independent.');
        $system = $report['account_reconciliation']['system'];
        $this->assertSame(0, $system['accounts_checked']);
        $clearing = collect($system['accounts'])->firstWhere('purpose', 'provider_clearing');
        $this->assertFalse($clearing['complete']);
        $this->assertFalse($clearing['projection_checked']);
        $this->assertNull($clearing['projection_matches']);
        $this->assertNull($clearing['ledger_minor']);
        $this->assertSame(0, $clearing['postings_scanned']);
        $this->assertContains('system_posting_scan_limit', $report['incomplete_reasons']);
    }

    public function test_native_wallet_http_dto_is_schema_and_balances_not_legacy_balance(): void
    {
        [$user] = $this->purchase();
        $this->fund($user);
        Sanctum::actingAs($user);
        $this->getJson('/txapi/billing/wallet')->assertOk()->assertJsonPath('data.schema', 'native-wallet/v1')
            ->assertJsonPath('data.balances.0.currency', 'CNY')->assertJsonPath('data.balances.0.available_minor', '5000');
    }

    private function fund(User $user, string $amount = '5000'): array
    {
        $key = 'fund:'.bin2hex(random_bytes(8));
        $recharge = app(NativeRechargeService::class)->create($user->id, $amount, $this->payment->id, $key);
        $attempt = app(NativePaymentAttempts::class)->prepareRecharge($user->id, (int) $recharge['recharge_id'],
            $this->payment->id, 'merchant-1', 'attempt:'.$key);
        return app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => 'real:'.$key]));
    }

    private function channelPayment(): array
    {
        [$user, $order] = $this->purchase();
        $attempt = app(NativePaymentAttempts::class)->prepare($user->id, $order->id, $this->payment->id, 'merchant-1', 'channel:'.$order->id);
        $settled = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => 'channel-real:'.$order->id]));
        return [$user, $order, $settled];
    }

    private function reserve(User $user, Order $order, string $amount, string $key): array
    {
        return app(NativeBillingTransaction::class)->run($user->id, function (array $installation) use ($user, $order, $amount, $key): array {
            app(NativeSubscriptionStore::class)->lockAccount($user->id, time(), $installation['installation_id']);
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $terms = OrderPurchaseTerms::query()->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            return app(NativeWalletReservations::class)->reserveLocked($installation, $user->id, $locked, $terms, $amount, $key);
        });
    }

    private function admin(): User
    {
        [, $admin] = app(NativeIdentityService::class)->create('admin-'.bin2hex(random_bytes(8)).'@example.test', 'runtime-password');
        DB::table('tx_user')->where('id', $admin->id)->update(['is_admin' => true]);
        return $admin;
    }

    private function walletBalance(User $user, string $purpose): string
    {
        return (string) (DB::table('tx_money_account')->where('owner_id', $user->id)->where('purpose', $purpose)->value('balance_minor') ?? '0');
    }

    private function tableSnapshots(array $tables): array
    {
        $snapshots = [];
        foreach ($tables as $table) {
            $query = DB::table($table);
            if ($table === 'tx_subscription_slot') {
                $query->orderBy('user_id')->orderBy('slot_no');
            } else {
                $query->orderBy('id');
            }
            $snapshots[$table] = $query->get()->toJson();
        }
        return $snapshots;
    }
}
