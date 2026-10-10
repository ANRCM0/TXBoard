<?php

namespace Tests\Feature\Txapi;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletRecharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TxapiAdminPaymentDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['secure_path' => 'payment_admin_security']);
    }

    public function test_delete_is_scoped_to_admin_and_correct_dynamic_path(): void
    {
        $normal = $this->user('normal-pay@example.test');
        $admin = $this->user('admin-pay@example.test', true);
        $method = $this->payment('auth-protection');
        $path = '/txapi/admin/payment_admin_security/payment-methods/' . $method->id . '/delete';

        $this->postJson($path)->assertStatus(403);
        Sanctum::actingAs($normal);
        $this->postJson($path)->assertStatus(403);
        Sanctum::actingAs($admin);
        $this->postJson('/txapi/admin/guessed/payment-methods/' . $method->id . '/delete')
            ->assertStatus(404);
        $this->assertNotNull($method->fresh());
    }

    public function test_native_refuses_deletion_if_order_history_exists(): void
    {
        $admin = $this->user('order-pay-admin@example.test', true);
        $owner = $this->user('order-pay-owner@example.test');
        $method = $this->payment('order-guard');
        $order = Order::create([
            'user_id' => $owner->id, 'plan_id' => 1,
            'payment_id' => $method->id, 'trade_no' => 'GUARD-ORDER-2026',
            'period' => 'monthly', 'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED, 'total_amount' => 1000,
        ]);
        Sanctum::actingAs($admin);
        $native = '/txapi/admin/payment_admin_security/payment-methods/' . $method->id . '/delete';
        $this->postJson($native)->assertStatus(409)->assertJsonPath('error.code', 'PAYMENT_IN_USE');

        $this->assertNotNull($method->fresh());
        $this->assertSame($method->id, (int) $order->fresh()->payment_id);
        $this->assertSame('order-guard',
            Payment::query()->whereKey($method->id)->value('uuid'));
    }

    public function test_native_refuses_deletion_if_recharge_history_exists(): void
    {
        $admin = $this->user('wallet-pay-admin@example.test', true);
        $owner = $this->user('wallet-pay-owner@example.test');
        $method = $this->payment('wallet-guard');
        $recharge = WalletRecharge::query()->create([
            'user_id' => $owner->id, 'payment_id' => $method->id,
            'trade_no' => 'WRPAYMENTGUARD2026',
            'request_key' => 'f01a1111-1111-4111-8111-111111111111',
            'amount_minor' => 1500, 'fee_minor' => 100,
            'status' => WalletRecharge::STATUS_PENDING,
        ]);
        Sanctum::actingAs($admin);
        $native = '/txapi/admin/payment_admin_security/payment-methods/' . $method->id . '/delete';
        $this->postJson($native)->assertStatus(409);

        $this->assertNotNull($method->fresh());
        $this->assertSame($method->id, (int) $recharge->fresh()->payment_id);
    }

    public function test_unused_payment_method_can_be_deleted_and_not_deleted_twice(): void
    {
        $admin = $this->user('fresh-pay-admin@example.test', true);
        $unused = $this->payment('unused-guard');
        Sanctum::actingAs($admin);
        $path = '/txapi/admin/payment_admin_security/payment-methods/' . $unused->id . '/delete';
        $this->postJson($path)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertNull(Payment::find($unused->id));
        $this->postJson($path)->assertStatus(404);
        $this->postJson('/txapi/admin/payment_admin_security/payment-methods/0/delete')
            ->assertStatus(404);
    }

    private function user(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email,
            'password' => 'example-password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => $admin, 'banned' => 0,
        ]);
    }

    private function payment(string $uuid): Payment
    {
        return Payment::create([
            'uuid' => $uuid, 'payment' => 'EPay',
            'name' => 'Secured payment method',
            'config' => ['key' => 'do-not-leak'],
            'enable' => true, 'sort' => 0,
        ]);
    }
}
