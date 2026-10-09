<?php

namespace Tests\Feature\Txapi;

use App\Models\CommissionLog;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_order_uses_same_wallet_reservation_and_cancel_refunds_once(): void
    {
        $user = $this->user('buyer-p3@example.test', ['balance' => 350]);
        $plan = $this->plan();
        Sanctum::actingAs($user);
        $this->getJson('/txapi/billing/wallet')->assertOk()
            ->assertJsonPath('data.balance_minor', 350)
            ->assertJsonPath('data.commission_balance_minor', 0);

        $created = $this->postJson('/txapi/orders', [
            'plan_id' => $plan->id, 'period' => Plan::PERIOD_MONTHLY,
        ]);
        $created->assertStatus(201)->assertJsonStructure(['data' => ['trade_no'], 'request_id']);
        $tradeNo = $created->json('data.trade_no');
        $order = Order::where('trade_no', $tradeNo)->firstOrFail();
        $this->assertSame(Order::STATUS_PENDING, (int) $order->status);
        $this->assertSame(350, (int) $order->balance_amount);
        $this->assertSame(650, (int) $order->total_amount);
        $this->assertSame(0, (int) $user->fresh()->balance);
        $this->postJson('/txapi/orders', [
            'plan_id' => $plan->id, 'period' => Plan::PERIOD_MONTHLY,
        ])->assertStatus(409)->assertJsonPath('error.code', 'ORDER_REJECTED');

        $this->postJson("/txapi/orders/{$tradeNo}/cancel")
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(350, (int) $user->fresh()->balance);
        $this->postJson("/txapi/orders/{$tradeNo}/cancel")
            ->assertStatus(409)->assertJsonPath('error.code', 'ORDER_CONFLICT');
        $this->assertSame(350, (int) $user->fresh()->balance);
    }

    public function test_coupon_preview_and_reservation_refund_use_canonical_period_and_minor_units(): void
    {
        $user = $this->user('coupon-p3@example.test');
        $plan = $this->plan();
        $coupon = Coupon::create([
            'code' => 'P3TESTCOUPON', 'name' => 'Discount',
            'type' => 1, 'value' => 200, 'show' => true,
            'limit_use' => 2,
        ]);
        Sanctum::actingAs($user);
        $this->postJson('/txapi/billing/coupons/check', [
            'code' => $coupon->code, 'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
        ])->assertOk()->assertJsonPath('data.value_minor', 200)
            ->assertJsonPath('data.percent', null);

        $create = $this->postJson('/txapi/orders', [
            'plan_id' => $plan->id, 'period' => 'month_price',
            'coupon_code' => $coupon->code,
        ]);
        $create->assertStatus(201);
        $tradeNo = $create->json('data.trade_no');
        $this->assertSame(800, (int) Order::where('trade_no', $tradeNo)->value('total_amount'));
        $this->assertSame(1, (int) $coupon->fresh()->limit_use);
        $this->postJson('/txapi/orders/'.$tradeNo.'/cancel')->assertOk();
        $this->assertSame(2, (int) $coupon->fresh()->limit_use);
    }

    public function test_unauthorized_or_wrong_owner_cannot_cancel_order_or_read_finances(): void
    {
        $this->getJson('/txapi/billing/wallet')->assertStatus(401);
        $this->getJson('/txapi/billing/commissions')->assertStatus(401);
        $this->postJson('/txapi/orders', [])->assertStatus(401);

        $owner = $this->user('owner-p3@example.test');
        $outsider = $this->user('other-p3@example.test');
        $plan = $this->plan();
        Sanctum::actingAs($owner);
        $trade = $this->postJson('/txapi/orders', [
            'plan_id' => $plan->id, 'period' => 'month_price',
        ])->json('data.trade_no');
        Sanctum::actingAs($outsider);
        $this->postJson('/txapi/orders/'.$trade.'/cancel')->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
        $this->getJson('/txapi/orders/'.$trade)->assertStatus(404);
        $this->assertSame(Order::STATUS_PENDING, (int) Order::where('trade_no', $trade)->value('status'));
    }

    public function test_payment_methods_are_whitelisted_and_commission_history_is_scoped(): void
    {
        $user = $this->user('commission-p3@example.test');
        $outsider = $this->user('elsewhere-p3@example.test');
        Payment::create([
            'uuid' => 'p3-method-enabled', 'payment' => 'EPay', 'name' => 'P3 Method',
            'enable' => true, 'config' => ['key' => 'never-return-this-key'],
            'handling_fee_fixed' => 20, 'handling_fee_percent' => 2,
        ]);
        Payment::create([
            'uuid' => 'p3-method-disabled', 'payment' => 'EPay', 'name' => 'Inactive',
            'enable' => false, 'config' => ['key' => 'hidden'],
        ]);
        CommissionLog::create([
            'invite_user_id' => $user->id, 'trade_no' => 'P3-COMM-1',
            'get_amount' => 55, 'order_amount' => 1000,
        ]);
        CommissionLog::create([
            'invite_user_id' => $outsider->id, 'trade_no' => 'P3-HIDDEN',
            'get_amount' => 777, 'order_amount' => 1000,
        ]);
        Sanctum::actingAs($user);
        $methods = $this->getJson('/txapi/billing/payment-methods');
        $methods->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.fee_fixed_minor', 20);
        $this->assertStringNotContainsString('never-return-this-key', $methods->getContent());
        $this->assertStringNotContainsString('Inactive', $methods->getContent());

        $commission = $this->getJson('/txapi/billing/commissions?per_page=1');
        $commission->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.earned_minor', 55);
        $this->assertStringNotContainsString('P3-HIDDEN', $commission->getContent());
        $this->getJson('/txapi/billing/commissions?per_page=101')->assertStatus(422);
    }

    private function user(string $email, array $props = []): User
    {
        return User::create(array_merge([
            'email' => $email, 'password' => 'test-only-hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'balance' => 0,
            'commission_balance' => 0, 'banned' => 0, 'expired_at' => 0,
        ], $props));
    }

    private function plan(): Plan
    {
        return Plan::create([
            'name' => 'P3 billing plan', 'group_id' => 1,
            'show' => true, 'sell' => true, 'renew' => true,
            'capacity_limit' => null, 'sort' => 0, 'transfer_enable' => 2,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
        ]);
    }
}
