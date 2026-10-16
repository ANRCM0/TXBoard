<?php

namespace Tests\Feature\Txapi;

use App\Domains\Billing\NativeMoneyLedger;
use App\Http\Middleware\EnsureInstallationAuthority;
use App\Models\Payment;
use App\Models\User;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\NativePaymentTestCase;

final class TxapiWalletRechargeTest extends NativePaymentTestCase
{
    private const ROOT = '/txapi/billing/recharges';
    private const SECRET = 'wallet-test-secret';
    protected string $publicationCurrency = 'CNY';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://core.example.test']);
        $this->payment->update([
            'payment' => 'EPay', 'name' => 'Wallet EPay',
            'handling_fee_fixed' => 0, 'handling_fee_percent' => 0,
            'config' => ['pid' => '42', 'key' => self::SECRET,
                'url' => 'https://payment.invalid/gateway', 'type' => 'alipay'],
        ]);
        // Financial validation must not exhaust the separate HTTP creation throttle.
        $this->withoutMiddleware(ThrottleRequests::class);
        Http::preventStrayRequests();
    }

    public function test_recharge_requires_auth_and_string_minor_units_and_is_permanently_idempotent(): void
    {
        $user = $this->user('topup-owner@example.test');
        $body = ['amount_minor' => '1500', 'payment_method_id' => (string) $this->payment->id];
        $key = (string) Str::uuid();
        foreach (['/txapi/billing/wallet', '/txapi/billing/recharge-payment-methods', self::ROOT] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        $this->postJson(self::ROOT, $body, ['Idempotency-Key' => $key])->assertUnauthorized();
        $this->postJson(self::ROOT.'/WR'.str_repeat('A', 28).'/checkout')->assertUnauthorized();
        Sanctum::actingAs($user);
        $this->postJson(self::ROOT, $body)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertHeader('Cache-Control', 'private, no-store');
        $this->postJson(self::ROOT, $body, ['Idempotency-Key' => 'not-a-uuid'])->assertUnprocessable();
        foreach ([0, 100, 100.5, '0', '99', '500001', '100.5', '00100', '9223372036854775808'] as $invalid) {
            $this->postJson(self::ROOT, array_replace($body, ['amount_minor' => $invalid]),
                ['Idempotency-Key' => $key])->assertUnprocessable()->assertHeader('Cache-Control', 'private, no-store');
        }
        $this->postJson(self::ROOT, array_replace($body, ['payment_method_id' => '999999']),
            ['Idempotency-Key' => $key])->assertUnprocessable()->assertJsonPath('error.code', 'RECHARGE_PROVIDER_UNAVAILABLE');
        $this->assertSame(0, DB::table('tx_native_recharge')->count());
        $this->assertSame(0, DB::table('tx_payment_dispatch')->count());
        DB::table('tx_user')->where('id', $user->id)->update(['balance' => 2147483600, 'commission_balance' => 123]);
        $created = $this->postJson(self::ROOT, $body, ['Idempotency-Key' => $key])->assertCreated()
            ->assertHeader('Cache-Control', 'private, no-store')
            ->assertJsonPath('data.amount_minor', '1500')->assertJsonPath('data.fee_minor', '0')
            ->assertJsonPath('data.total_minor', '1500')->assertJsonPath('data.currency', 'CNY')
            ->assertJsonPath('data.payment_method_id', (string) $this->payment->id)
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.paid_at', null);
        $trade = $created->json('data.trade_no');
        $this->assertMatchesRegularExpression('/\AWR[A-F0-9]{28}\z/D', $trade);
        $this->assertIsString($created->json('data.recharge_id'));
        $this->assertIsString($created->json('data.created_at'));
        $this->assertStringNotContainsString($key, $created->getContent());
        $this->assertStringNotContainsString(self::SECRET, $created->getContent());
        $this->postJson(self::ROOT, $body, ['Idempotency-Key' => $key])
            ->assertCreated()->assertJsonPath('data', $created->json('data'));
        $this->postJson(self::ROOT, array_replace($body, ['amount_minor' => '2000']), ['Idempotency-Key' => $key])
            ->assertConflict()->assertJsonPath('error.code', 'RECHARGE_IDEMPOTENCY_CONFLICT')
            ->assertHeader('Cache-Control', 'private, no-store');
        $this->assertSame(1, DB::table('tx_native_recharge')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('tx_payment_attempt')->count());
        $this->assertSame(0, DB::table('tx_payment_receipt')->count());
        $this->assertSame(0, DB::table('tx_money_journal')->count());
        $this->getJson('/txapi/billing/wallet')->assertOk()->assertHeader('Cache-Control', 'private, no-store')
            ->assertJsonPath('data.schema', 'native-wallet/v1')->assertJsonPath('data.balances', []);
        $this->assertSame(2147483600, (int) $user->fresh()->balance);
        $this->assertSame(123, (int) $user->fresh()->commission_balance);
    }

    public function test_native_cursor_and_detail_are_owner_scoped_and_bounded(): void
    {
        $owner = $this->user('topup-pagination@example.test');
        $other = $this->user('topup-other@example.test');
        Sanctum::actingAs($owner);
        $first = $this->createRecharge();
        Sanctum::actingAs($other);
        $foreign = $this->createRecharge();
        Sanctum::actingAs($owner);
        $second = $this->createRecharge('2000');
        $this->getJson(self::ROOT.'?limit=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.recharge_id', $first['recharge_id'])
            ->assertJsonPath('meta.next_after_id', $first['recharge_id'])->assertJsonMissingPath('meta.total')
            ->assertHeader('Cache-Control', 'private, no-store');
        $this->getJson(self::ROOT.'?limit=1&after_id='.$first['recharge_id'])->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.recharge_id', $second['recharge_id'])
            ->assertJsonPath('meta.next_after_id', $second['recharge_id']);
        $this->getJson(self::ROOT.'?after_id='.$second['recharge_id'])->assertOk()
            ->assertJsonPath('data', [])->assertJsonPath('meta.next_after_id', null);
        foreach (['limit=0', 'limit=51', 'after_id=-1', 'after_id=1.5'] as $query) {
            $this->getJson(self::ROOT.'?'.$query)->assertUnprocessable()->assertHeader('Cache-Control', 'private, no-store');
        }
        $this->getJson(self::ROOT.'/'.$first['trade_no'])->assertOk()->assertJsonPath('data', $first)
            ->assertHeader('Cache-Control', 'private, no-store');
        $this->getJson(self::ROOT.'/'.$foreign['trade_no'])->assertNotFound()
            ->assertJsonPath('error.code', 'RECHARGE_MISSING')->assertHeader('Cache-Control', 'private, no-store');
        $this->postJson(self::ROOT.'/'.$foreign['trade_no'].'/checkout')->assertNotFound()
            ->assertHeader('Cache-Control', 'private, no-store');
        $this->getJson(self::ROOT.'/not-a-native-reference')->assertNotFound();
        Sanctum::actingAs($other);
        $this->getJson(self::ROOT)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.recharge_id', $foreign['recharge_id']);
        $this->getJson(self::ROOT.'/'.$first['trade_no'])->assertNotFound();
        $this->postJson(self::ROOT.'/'.$first['trade_no'].'/checkout')->assertNotFound();
        $this->assertSame(0, DB::table('tx_payment_attempt')->count());
    }

    public function test_frozen_dispatch_and_signed_callback_credit_the_native_ledger_once_not_user_balance(): void
    {
        $owner = $this->user('topup-callback@example.test');
        $other = $this->user('topup-attempt-outsider@example.test');
        DB::table('tx_user')->where('id', $owner->id)->update(['balance' => 321, 'commission_balance' => 123]);
        Sanctum::actingAs($owner);
        $recharge = $this->createRecharge();
        $path = self::ROOT.'/'.$recharge['trade_no'].'/checkout';
        $this->postJson($path, ['token' => 'must-not-be-accepted'])->assertUnprocessable()
            ->assertJsonPath('error.fields', ['token'])->assertHeader('Cache-Control', 'private, no-store');
        $this->assertSame(0, DB::table('tx_payment_dispatch')->count());
        $checkout = $this->postJson($path)->assertOk()->assertJsonPath('data.payment.type', 1)
            ->assertJsonPath('data.attempt.intent_type', 'recharge')->assertJsonPath('data.attempt.amount_minor', '1000')
            ->assertJsonPath('data.attempt.currency', 'CNY')->assertHeader('Cache-Control', 'private, no-store');
        $attemptId = $checkout->json('data.attempt.attempt_id');
        $url = $checkout->json('data.payment.data');
        $this->assertStringStartsWith('https://payment.invalid/gateway/submit.php?', $url);
        $this->assertStringNotContainsString(self::SECRET, $checkout->getContent());
        $this->assertStringNotContainsString(self::SECRET, DB::table('tx_payment_dispatch')->value('snapshot_ciphertext'));
        $params = $this->notification($attemptId);
        $this->assertNotSame($recharge['trade_no'], $params['out_trade_no']);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame($params['out_trade_no'], $query['out_trade_no']);
        $this->assertSame('10.00', $query['money']);
        $this->postJson($path)->assertOk()->assertJsonPath('data.attempt.attempt_id', $attemptId)
            ->assertJsonPath('data.payment.data', $url);
        $this->assertSame(1, DB::table('tx_payment_attempt')->count());
        $this->assertSame(1, DB::table('tx_payment_dispatch')->count());
        $this->assertSame(0, DB::table('tx_money_journal')->count());
        $this->getJson('/txapi/billing/payment-attempts/'.$attemptId)->assertOk()->assertJsonPath('data.status', 'pending');
        Sanctum::actingAs($other);
        $this->getJson('/txapi/billing/payment-attempts/'.$attemptId)->assertNotFound();
        $this->postJson('/txapi/billing/payment-attempts/'.$attemptId.'/query')->assertNotFound();
        Sanctum::actingAs($owner);
        $callback = '/txapi/payment/webhook/EPay/'.$this->payment->uuid;
        $this->payment->update(['enable' => false, 'uuid' => bin2hex(random_bytes(16)),
            'config' => ['url' => 'https://changed.example.test', 'pid' => '99', 'key' => 'changed-secret']]);
        $this->postJson($path)->assertConflict()->assertJsonPath('error.code', 'PAYMENT_PROVIDER_UNAVAILABLE')
            ->assertHeader('Cache-Control', 'private, no-store');
        $this->post($callback, $params)->assertOk()->assertContent('success');
        $this->post($callback, $params)->assertOk()->assertContent('success');
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
        $this->assertSame(2, DB::table('tx_money_posting')->count());
        $this->assertSame('wallet_credit', DB::table('tx_money_journal')->value('business_type'));
        $this->assertSame('succeeded', DB::table('tx_payment_attempt')->value('status'));
        $this->assertSame('paid', DB::table('tx_native_recharge')->value('status'));
        $this->getJson(self::ROOT.'/'.$recharge['trade_no'])->assertOk()->assertJsonPath('data.status', 'paid');
        $this->getJson('/txapi/billing/wallet')->assertOk()->assertJsonPath('data', [
            'schema' => 'native-wallet/v1',
            'balances' => [['currency' => 'CNY', 'available_minor' => '1000', 'reserved_minor' => '0']],
        ])->assertHeader('Cache-Control', 'private, no-store');
        $this->assertSame(321, (int) $owner->fresh()->balance);
        $this->assertSame(123, (int) $owner->fresh()->commission_balance);
        $this->assertSame(0, DB::table('tx_order_payment')->count());
        $this->assertSame(0, DB::table('tx_billing_work')->count());
        Http::assertNothingSent();
    }

    public function test_tampered_underpaid_wrong_binding_and_replayed_provider_transactions_cannot_credit(): void
    {
        $owner = $this->user('topup-security@example.test');
        Sanctum::actingAs($owner);
        $recharge = $this->createRecharge();
        $started = $this->postJson(self::ROOT.'/'.$recharge['trade_no'].'/checkout')->assertOk();
        $attemptId = $started->json('data.attempt.attempt_id');
        $params = $this->notification($attemptId);
        $callback = '/txapi/payment/webhook/EPay/'.$this->payment->uuid;
        foreach ([array_replace($params, ['sign' => 'forged']), $this->notification($attemptId, ['money' => '9.99']),
            $this->notification($attemptId, ['pid' => '43']), array_replace($params, ['unsigned_extra' => 'tampered'])] as $rejected) {
            $this->post($callback, $rejected)->assertStatus(500)->assertContent('fail');
        }
        $this->post('/txapi/payment/webhook/EPay/wrong-uuid', $params)->assertStatus(500)->assertContent('fail');
        $this->post('/txapi/payment/webhook/Other/'.$this->payment->uuid, $params)->assertStatus(500)->assertContent('fail');
        // A WR reference without its frozen dispatch must not enter the historical recharge path.
        $this->post($callback, $this->notification($attemptId, ['out_trade_no' => $recharge['trade_no']]))
            ->assertUnprocessable()->assertContent('fail');
        $this->assertSame(0, DB::table('tx_payment_receipt')->count());
        $this->assertSame(0, DB::table('tx_money_journal')->count());
        $this->assertSame('pending', DB::table('tx_native_recharge')->value('status'));
        $this->getJson('/txapi/billing/wallet')->assertOk()->assertJsonPath('data.balances', []);
        $this->post($callback, $params)->assertOk()->assertContent('success');
        $this->post($callback, $this->notification($attemptId, ['trade_no' => 'different-transaction']))
            ->assertStatus(500)->assertContent('fail');
        $second = $this->createRecharge();
        $next = $this->postJson(self::ROOT.'/'.$second['trade_no'].'/checkout')->assertOk();
        $this->post($callback, $this->notification($next->json('data.attempt.attempt_id'), ['trade_no' => $params['trade_no']]))
            ->assertStatus(500)->assertContent('fail');
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
        $this->assertSame(2, DB::table('tx_money_posting')->count());
        $this->assertSame('pending', DB::table('tx_native_recharge')->where('id', $second['recharge_id'])->value('status'));
        $this->assertSame('1000', $this->walletBalance($owner));
        Http::assertNothingSent();
    }

    public function test_native_64_bit_wallet_overflow_rejects_settlement_without_partial_money_or_receipt_writes(): void
    {
        $owner = $this->user('overflow-wallet@example.test');
        $nearMaximum = '9223372036854775708';
        app(NativeMoneyLedger::class)->post($owner->id, [
            'business_type' => 'adjustment', 'business_ref' => 'overflow-fixture',
            'idempotency_key' => 'overflow-fixture', 'currency' => 'CNY', 'occurred_at' => (string) time(),
            'postings' => [
                ['owner_id' => '0', 'purpose' => 'provider_clearing', 'side' => 'debit', 'amount_minor' => $nearMaximum],
                ['owner_id' => (string) $owner->id, 'purpose' => 'wallet_available', 'side' => 'credit', 'amount_minor' => $nearMaximum],
            ],
        ]);
        Sanctum::actingAs($owner);
        $this->getJson('/txapi/billing/wallet')->assertOk()->assertJsonPath('data.balances.0.available_minor', $nearMaximum);
        $recharge = $this->createRecharge('100');
        $checkout = $this->postJson(self::ROOT.'/'.$recharge['trade_no'].'/checkout')->assertOk();
        $this->post('/txapi/payment/webhook/EPay/'.$this->payment->uuid,
            $this->notification($checkout->json('data.attempt.attempt_id')))->assertStatus(500)->assertContent('fail');
        $this->assertSame($nearMaximum, $this->walletBalance($owner));
        $this->assertSame(0, DB::table('tx_payment_receipt')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
        $this->assertSame(2, DB::table('tx_money_posting')->count());
        $this->assertSame('pending', DB::table('tx_payment_attempt')->value('status'));
        $this->getJson(self::ROOT.'/'.$recharge['trade_no'])->assertOk()->assertJsonPath('data.status', 'pending');
        Http::assertNothingSent();
    }

    public function test_methods_exclude_unverified_disabled_fee_bearing_and_invalid_configurations_and_hide_secrets(): void
    {
        $unsupported = $this->payment->replicate();
        $unsupported->fill(['uuid' => 'unsupported-wallet', 'payment' => 'StripeCredit',
            'name' => 'Not a native provider', 'config' => ['secret_key' => 'never-return-provider-secret']])->saveOrFail();
        foreach ([['uuid' => 'disabled-wallet', 'enable' => false], ['uuid' => 'fixed-fee-wallet', 'handling_fee_fixed' => 1],
            ['uuid' => 'percent-fee-wallet', 'handling_fee_percent' => 1],
            ['uuid' => 'invalid-config-wallet', 'config' => ['pid' => '42', 'key' => self::SECRET, 'url' => 'http://unsafe.test']]] as $attributes) {
            $method = $this->payment->replicate();
            $method->fill($attributes)->saveOrFail();
        }
        Sanctum::actingAs($this->user('topup-methods@example.test'));
        $methods = $this->getJson('/txapi/billing/recharge-payment-methods')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $this->payment->id)->assertJsonPath('data.0.payment', 'EPay')
            ->assertJsonPath('data.0.currency', 'CNY')->assertJsonPath('data.0.handling_fee_fixed', '0')
            ->assertJsonPath('data.0.handling_fee_percent', '0')->assertJsonPath('data.0.external_refund_supported', false)
            ->assertJsonMissingPath('data.0.config')->assertHeader('Cache-Control', 'private, no-store');
        $this->assertStringNotContainsString(self::SECRET, $methods->getContent());
        $this->assertStringNotContainsString('never-return-provider-secret', $methods->getContent());
        $this->assertStringNotContainsString('Not a native provider', $methods->getContent());
        foreach ([$unsupported, Payment::query()->where('uuid', 'fixed-fee-wallet')->firstOrFail(),
            Payment::query()->where('uuid', 'percent-fee-wallet')->firstOrFail()] as $method) {
            $response = $this->postJson(self::ROOT, ['amount_minor' => '1000', 'payment_method_id' => (string) $method->id],
                ['Idempotency-Key' => (string) Str::uuid()])->assertStatus($method->id === $unsupported->id ? 409 : 422)
                ->assertHeader('Cache-Control', 'private, no-store');
            $this->assertStringNotContainsString(self::SECRET, $response->getContent());
            $this->assertStringNotContainsString('never-return-provider-secret', $response->getContent());
        }
        $this->assertSame(0, DB::table('tx_native_recharge')->count());
        $this->assertSame(0, DB::table('tx_payment_dispatch')->count());
    }

    public function test_native_recharge_foreign_keys_preserve_account_and_payment_history(): void
    {
        $owner = $this->user('wallet-history@example.test');
        Sanctum::actingAs($owner);
        $recharge = $this->createRecharge();
        $this->assertForeignKeyRejected(fn () => DB::table('tx_native_recharge')->where('id', $recharge['recharge_id'])
            ->update(['user_id' => 999999]));
        $this->assertForeignKeyRejected(fn () => DB::table('tx_native_recharge')->where('id', $recharge['recharge_id'])
            ->update(['payment_id' => 999999]));
        $this->assertForeignKeyRejected(fn () => DB::table('tx_user')->where('id', $owner->id)->delete());
        $this->assertForeignKeyRejected(fn () => DB::table('tx_payment')->where('id', $this->payment->id)->delete());
        $this->assertNotNull($owner->fresh());
        $this->assertNotNull($this->payment->fresh());
        $this->getJson(self::ROOT.'/'.$recharge['trade_no'])->assertOk()->assertJsonPath('data', $recharge);
    }

    public function test_every_wallet_action_returns_private_native_not_ready_without_fallback(): void
    {
        $owner = $this->user('wallet-not-ready@example.test');
        Sanctum::actingAs($owner);
        $recharge = $this->createRecharge();
        DB::table('tx_user')->where('id', $owner->id)->update(['balance' => 12345]);
        // Exercise controller-local admission instead of the global middleware's earlier rejection.
        $this->withoutMiddleware(EnsureInstallationAuthority::class);
        config(['core_installation.worker_epoch' => '1']);
        foreach ([['GET', '/txapi/billing/wallet'], ['GET', '/txapi/billing/recharge-payment-methods'],
            ['GET', self::ROOT], ['GET', self::ROOT.'/'.$recharge['trade_no']], ['POST', self::ROOT],
            ['POST', self::ROOT.'/'.$recharge['trade_no'].'/checkout']] as [$verb, $path]) {
            $body = $path === self::ROOT && $verb === 'POST'
                ? ['amount_minor' => '1000', 'payment_method_id' => (string) $this->payment->id] : [];
            $response = $this->json($verb, $path, $body, ['Idempotency-Key' => (string) Str::uuid()])
                ->assertStatus(503)->assertJsonPath('error.code', 'INSTALLATION_AUTHORITY_NATIVE_NOT_READY')
                ->assertHeader('Cache-Control', 'private, no-store')->assertJsonMissingPath('data');
            $this->assertStringNotContainsString(self::SECRET, $response->getContent());
            $this->assertStringNotContainsString('12345', $response->getContent());
        }
        $this->assertSame(1, DB::table('tx_native_recharge')->count());
        $this->assertSame(0, DB::table('tx_payment_attempt')->count());
        $this->assertSame(0, DB::table('tx_payment_receipt')->count());
        $this->assertSame(0, DB::table('tx_money_journal')->count());
        Http::assertNothingSent();
    }

    private function user(string $email): User
    {
        [, $user] = app(NativeIdentityService::class)->create($email, 'runtime-password');
        return $user;
    }

    private function createRecharge(string $amount = '1000'): array
    {
        return $this->postJson(self::ROOT, [
            'amount_minor' => $amount, 'payment_method_id' => (string) $this->payment->id,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()
            ->assertHeader('Cache-Control', 'private, no-store')->json('data');
    }

    private function notification(int|string $attemptId, array $overrides = []): array
    {
        $ciphertext = DB::table('tx_payment_dispatch')->where('attempt_id', $attemptId)->value('snapshot_ciphertext');
        $snapshot = json_decode(Crypt::decryptString($ciphertext), true, 16, JSON_THROW_ON_ERROR);
        $params = array_replace([
            'pid' => $snapshot['pid'], 'out_trade_no' => $snapshot['provider_order_ref'],
            'trade_no' => 'wallet-channel-'.$attemptId,
            'money' => app(\Plugin\Epay\NativeAdapter::class)->decimal($snapshot['amount_minor']),
            'trade_status' => 'TRADE_SUCCESS', 'sign_type' => 'MD5',
        ], $overrides);
        $signed = $params;
        unset($signed['sign'], $signed['sign_type']);
        ksort($signed, SORT_STRING);
        $params['sign'] = md5(stripslashes(urldecode(http_build_query($signed))).$snapshot['key']);
        return $params;
    }

    private function walletBalance(User $owner): string
    {
        return (string) DB::table('tx_money_account')->where('owner_id', $owner->id)
            ->where('purpose', 'wallet_available')->where('currency', 'CNY')->value('balance_minor');
    }

    private function assertForeignKeyRejected(\Closure $action): void
    {
        try {
            DB::transaction($action);
            $this->fail('Native financial ownership/history must be protected by a foreign key.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('foreign key', strtolower($error->getMessage()));
        }
    }
}
