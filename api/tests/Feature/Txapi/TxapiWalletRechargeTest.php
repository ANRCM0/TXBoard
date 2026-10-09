<?php

namespace Tests\Feature\Txapi;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Plugin;
use App\Models\User;
use App\Models\WalletRecharge;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiWalletRechargeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Rate limits are exercised separately; financial validation tests
        // must not exhaust the 10/minute HTTP creation throttle.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Plugin::create([
            'name' => 'EPay', 'code' => 'epay', 'type' => 'payment',
            'version' => '1.0.0', 'is_enabled' => true, 'config' => '{}',
        ]);
        $this->app->forgetInstance(PluginManager::class);
        HookManager::reset();
    }

    public function test_recharge_is_owner_scoped_validated_idempotent_and_does_not_credit_before_callback(): void
    {
        $method = $this->payment();
        $user = $this->user('topup-owner@example.test');
        $other = $this->user('topup-other@example.test');
        $root = '/txapi/billing/recharges';
        $key = 'd2b01300-9d18-420d-a0d6-f213147d0001';
        $body = ['amount_minor' => 1500, 'payment_method_id' => $method->id];

        $this->postJson($root, $body, ['Idempotency-Key' => $key])->assertStatus(401);
        Sanctum::actingAs($user);
        $this->postJson($root, $body)->assertStatus(422);
        foreach ([0, 99, 500001, 100.5] as $invalid) {
            $this->postJson($root, array_merge($body, ['amount_minor' => $invalid]),
                ['Idempotency-Key' => $key])->assertStatus(422);
        }
        $this->postJson($root, ['amount_minor' => 1500, 'payment_method_id' => 99999],
            ['Idempotency-Key' => $key])->assertStatus(422);
        $created = $this->postJson($root, $body,
            ['Idempotency-Key' => $key])->assertCreated()
            ->assertJsonPath('data.amount_minor', 1500)
            ->assertJsonPath('data.fee_minor', 180)
            ->assertJsonPath('data.total_minor', 1680)
            ->assertJsonPath('data.status', WalletRecharge::STATUS_PENDING);
        $trade = (string) $created->json('data.trade_no');
        $this->assertStringStartsWith('WR', $trade);
        $this->assertSame(0, (int) $user->fresh()->balance);
        $this->assertSame(0, (int) $user->fresh()->commission_balance);
        $this->assertStringNotContainsString($key, $created->getContent());

        $this->postJson($root, $body, ['Idempotency-Key' => $key])
            ->assertCreated()->assertJsonPath('data.trade_no', $trade);
        $this->assertSame(1, WalletRecharge::query()->where('user_id', $user->id)->count());
        $this->postJson($root, array_merge($body, ['amount_minor' => 2000]),
            ['Idempotency-Key' => $key])->assertStatus(409);
        $this->getJson($root)->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.trade_no', $trade);
        $this->getJson($root . '/' . $trade)->assertOk()
            ->assertJsonPath('data.status', 0);
        $this->getJson($root . '?per_page=51')->assertStatus(422);

        Sanctum::actingAs($other);
        $this->getJson($root . '/' . $trade)->assertStatus(404);
        $this->postJson($root . '/' . $trade . '/checkout')->assertStatus(404);
        $this->getJson($root)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_existing_payment_checkout_and_verified_callback_credit_once_with_fees_excluded(): void
    {
        $method = $this->payment();
        $user = $this->user('topup-callback@example.test');
        Sanctum::actingAs($user);
        $key = 'e2b01300-9d18-420d-a0d6-f213147d0002';
        $trade = (string) $this->postJson('/txapi/billing/recharges', [
            'amount_minor' => 1000, 'payment_method_id' => $method->id,
        ], ['Idempotency-Key' => $key])->assertCreated()->json('data.trade_no');

        $checkout = $this->postJson('/txapi/billing/recharges/' . $trade . '/checkout', [])
            ->assertOk()->assertJsonPath('data.type', 1);
        $this->assertStringContainsString('payment.invalid/submit.php?', (string) $checkout->json('data.data'));
        $this->assertSame(0, (int) $user->fresh()->balance);

        $signed = $this->signature($trade, '11.30', 'paid-txn-01');
        $callback = '/txapi/payment/webhook/EPay/' . $method->uuid;
        $this->post($callback, $signed)->assertOk()->assertSeeText('success');
        $this->assertSame(1000, (int) $user->fresh()->balance);
        $this->assertSame(0, (int) $user->fresh()->commission_balance);
        $this->assertSame(WalletRecharge::STATUS_PAID, (int) WalletRecharge::query()
            ->where('trade_no', $trade)->value('status'));
        $this->getJson('/txapi/billing/recharges/' . $trade)
            ->assertOk()->assertJsonPath('data.status', 1);
        $this->getJson('/txapi/billing/wallet')
            ->assertOk()->assertJsonPath('data.balance_minor', 1000);
        // Legacy and native callbacks must share one durable idempotency record.
        $this->post('/api/v1/guest/payment/notify/EPay/' . $method->uuid, $signed)
            ->assertOk()->assertSeeText('success');
        $this->assertSame(1000, (int) $user->fresh()->balance);
        $this->post($callback, $this->signature($trade, '11.30', 'different-txn'))
            ->assertStatus(400);
        $this->assertSame(1000, (int) $user->fresh()->balance);
        $this->postJson('/txapi/billing/recharges/' . $trade . '/checkout')
            ->assertStatus(409);
    }

    public function test_tampered_underpaid_wrong_method_and_replayed_provider_transactions_cannot_credit(): void
    {
        $method = $this->payment();
        $otherMethod = $this->payment('another-merchant-wallet-uuid');
        $owner = $this->user('topup-security@example.test');
        Sanctum::actingAs($owner);
        $root = '/txapi/billing/recharges';
        $trade = (string) $this->postJson($root, [
            'amount_minor' => 1000, 'payment_method_id' => $method->id,
        ], ['Idempotency-Key' => 'e2b01300-9d18-420d-a0d6-f213147d0003'])->json('data.trade_no');
        $callback = '/txapi/payment/webhook/EPay/' . $method->uuid;
        $bad = $this->signature($trade, '11.30', 'wallet-txn-1');
        $bad['sign'] = 'forged';
        $this->post($callback, $bad)->assertStatus(422);
        $this->post($callback, $this->signature($trade, '11.29', 'wallet-txn-1'))
            ->assertStatus(400);
        $this->post('/txapi/payment/webhook/EPay/' . $otherMethod->uuid,
            $this->signature($trade, '11.30', 'wallet-txn-1'))->assertStatus(400);
        $this->assertSame(0, (int) $owner->fresh()->balance);

        // One valid provider transaction cannot be replayed into a second
        // wallet credit on another reference, even with a valid signature.
        $this->post($callback, $this->signature($trade, '11.30', 'wallet-txn-1'))->assertOk();
        $second = (string) $this->postJson($root, [
            'amount_minor' => 1000, 'payment_method_id' => $method->id,
        ], ['Idempotency-Key' => 'e2b01300-9d18-420d-a0d6-f213147d0004'])->json('data.trade_no');
        $this->post($callback, $this->signature($second, '11.30', 'wallet-txn-1'))
            ->assertStatus(400);
        $this->assertSame(1000, (int) $owner->fresh()->balance);
        $this->assertSame(WalletRecharge::STATUS_PENDING,
            (int) WalletRecharge::where('trade_no', $second)->value('status'));
    }

    public function test_recharge_methods_exclude_non_verified_provider_adapters_and_keep_configs_private(): void
    {
        $supported = $this->payment();
        Payment::create([
            'uuid' => 'unsafe-wallet-merchant', 'payment' => 'StripeCredit',
            'name' => 'Not audited for recharge', 'enable' => true,
            'config' => ['secret_key' => 'never-return-provider-secret'],
        ]);
        $user = $this->user('topup-methods@example.test');
        Sanctum::actingAs($user);
        $methods = $this->getJson('/txapi/billing/recharge-payment-methods')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $supported->id)
            ->assertJsonPath('data.0.payment', 'EPay');
        $this->assertStringNotContainsString('never-return-provider-secret', $methods->getContent());
        $this->assertStringNotContainsString('Not audited for recharge', $methods->getContent());
        $this->postJson('/txapi/billing/recharges', [
            'amount_minor' => 1000,
            'payment_method_id' => Payment::where('payment', 'StripeCredit')->value('id'),
        ], ['Idempotency-Key' => 'e2b01300-9d18-420d-a0d6-f213147d0005'])
            ->assertStatus(422);
    }

    public function test_account_with_wallet_recharge_history_cannot_be_physically_deleted(): void
    {
        admin_setting(['secure_path' => 'wallet-delete-guard']);
        $owner = $this->user('wallet-history@example.test');
        $admin = $this->user('wallet-root@example.test');
        $admin->is_admin = true;
        $admin->saveOrFail();
        $payment = $this->payment();
        Sanctum::actingAs($owner);
        $trade = (string) $this->postJson('/txapi/billing/recharges', [
            'amount_minor' => 1000, 'payment_method_id' => $payment->id,
        ], ['Idempotency-Key' => 'e2b01300-9d18-420d-a0d6-f213147d0006'])->json('data.trade_no');
        Sanctum::actingAs($admin);
        $this->postJson('/txapi/admin/wallet-delete-guard/users/' . $owner->id . '/delete')
            ->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNT_IN_USE');
        $this->assertNotNull($owner->fresh());
        $this->assertNotNull(WalletRecharge::where('trade_no', $trade)->first());
    }

    private function user(string $email): User
    {
        return User::create([
            'email' => $email, 'password' => 'test-password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0, 'commission_balance' => 0,
            'is_admin' => 0, 'is_staff' => 0, 'banned' => 0,
        ]);
    }

    private function payment(string $uuid = 'wallet-epay-merchant-uuid-001'): Payment
    {
        return Payment::create([
            'uuid' => $uuid, 'payment' => 'EPay', 'name' => 'Wallet EPay',
            'enable' => true, 'handling_fee_fixed' => 30,
            'handling_fee_percent' => 10,
            'config' => [
                'pid' => 'wallet-merchant', 'key' => 'wallet-test-secret',
                'url' => 'https://payment.invalid',
            ],
        ]);
    }

    private function signature(string $trade, string $money, string $providerTrade): array
    {
        $params = [
            'pid' => 'wallet-merchant', 'out_trade_no' => $trade,
            'trade_no' => $providerTrade, 'money' => $money,
            'trade_status' => 'TRADE_SUCCESS',
        ];
        ksort($params);
        $params['sign'] = md5(stripslashes(urldecode(http_build_query($params))) . 'wallet-test-secret');
        $params['sign_type'] = 'MD5';
        return $params;
    }
}
