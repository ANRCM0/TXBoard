<?php

namespace Tests\Feature\Txapi;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiAdminOrderActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_detail_hides_private_tokens_and_preserves_admin_money_fields(): void
    {
        admin_setting(['secure_path' => 'native_order_admin']);
        $admin = $this->user('order-native-admin@example.test', true);
        $owner = $this->user('native-order-owner@example.test', false);
        $plan = $this->plan();
        $order = $this->order($owner, $plan, 'TX-DETAIL-2026', 1900);
        $order->balance_amount = 500;
        $order->commission_balance = 120;
        $order->saveOrFail();
        $uri = '/txapi/admin/native_order_admin/orders/' . $order->id . '/detail';
        $this->getJson($uri)->assertStatus(403);
        Sanctum::actingAs($owner);
        $this->getJson($uri)->assertStatus(403);
        Sanctum::actingAs($admin);
        $this->getJson('/txapi/admin/guess/orders/'.$order->id.'/detail')->assertStatus(404);
        $response = $this->getJson($uri)->assertOk()
            ->assertJsonPath('data.trade_no', 'TX-DETAIL-2026')
            ->assertJsonPath('data.balance_amount', 500)
            ->assertJsonPath('data.commission_balance', 120)
            ->assertJsonPath('data.user.email', $owner->email)
            ->assertJsonPath('data.period', 'month_price');
        $this->assertStringNotContainsString($owner->token, $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->getJson('/txapi/admin/native_order_admin/orders/999999/detail')->assertStatus(404);
    }

    public function test_native_manual_paid_and_cancel_use_existing_state_machine_without_double_settlement(): void
    {
        admin_setting(['secure_path' => 'native_order_admin']);
        Bus::fake();
        $owner = $this->user('order-paid-owner@example.test', false);
        $admin = $this->user('order-settlement-admin@example.test', true);
        $plan = $this->plan();
        $pay = $this->order($owner, $plan, 'TX-MANUAL-PAID-2026', 1200);
        $cancel = $this->order($owner, $plan, 'TX-CANCEL-2026', 600);
        Sanctum::actingAs($owner);
        $this->postJson('/txapi/admin/native_order_admin/orders/'.$pay->trade_no.'/paid')
            ->assertStatus(403);
        Sanctum::actingAs($admin);
        $url = '/txapi/admin/native_order_admin/orders/';
        $this->postJson($url . $pay->trade_no . '/paid')
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(Order::STATUS_PROCESSING, (int) $pay->fresh()->status);
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
        $this->postJson($url . $pay->trade_no . '/paid')->assertStatus(409)
            ->assertJsonPath('error.code', 'ORDER_CONFLICT');
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
        $this->postJson($url . $pay->trade_no . '/cancel')->assertStatus(409);
        $this->postJson($url . $cancel->trade_no . '/cancel')
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(Order::STATUS_CANCELLED, (int) $cancel->fresh()->status);
        $this->postJson($url . $cancel->trade_no . '/cancel')->assertStatus(409);
        $this->postJson($url . $cancel->trade_no . '/paid')->assertStatus(409);
        $this->assertSame(Order::STATUS_CANCELLED, (int) $cancel->fresh()->status);
        $this->postJson($url . 'missing-trade/paid')->assertStatus(404);
        $this->postJson($url . 'missing-trade/cancel')->assertStatus(404);
    }

    private function user(string $email, bool $admin): User
    {
        return User::create([
            'email' => $email, 'password' => 'secret-never-expose',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'is_admin' => $admin,
            'balance' => 0, 'commission_balance' => 0, 'banned' => 0,
        ]);
    }

    private function plan(): Plan
    {
        return Plan::create([
            'name' => 'Native detail plan', 'group_id' => 1,
            'transfer_enable' => 2, 'show' => true, 'sell' => true,
            'renew' => true, 'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
        ]);
    }

    private function order(User $user, Plan $plan, string $trade, int $amount): Order
    {
        return Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'trade_no' => $trade, 'period' => Plan::PERIOD_MONTHLY,
            'type' => Order::TYPE_NEW_PURCHASE, 'status' => Order::STATUS_PENDING,
            'total_amount' => $amount, 'balance_amount' => 0,
        ]);
    }
}
