<?php

namespace Tests\Feature\Txapi;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_checkout_keeps_one_fulfillment_and_only_allows_owner(): void
    {
        Bus::fake();
        $owner = $this->user('payer@example.test');
        $outsider = $this->user('other@example.test');
        $order = $this->order($owner, 0);
        Sanctum::actingAs($outsider);
        $this->postJson('/txapi/orders/'.$order->trade_no.'/checkout')->assertStatus(404);
        Sanctum::actingAs($owner);
        $this->postJson('/txapi/orders/'.$order->trade_no.'/checkout')
            ->assertOk()->assertJsonPath('data.type', -1)
            ->assertJsonPath('data.data', true);
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        Bus::assertDispatchedTimes(\App\Jobs\OrderHandleJob::class, 1);
        $this->postJson('/txapi/orders/'.$order->trade_no.'/checkout')
            ->assertStatus(409)->assertJsonPath('error.code', 'ORDER_CONFLICT');
        Bus::assertDispatchedTimes(\App\Jobs\OrderHandleJob::class, 1);
    }

    public function test_rejects_negative_amount_and_missing_provider(): void
    {
        $owner = $this->user('reject@example.test');
        $order = $this->order($owner, 1000);
        $this->postJson('/txapi/orders/'.$order->trade_no.'/checkout')->assertStatus(401);
        Sanctum::actingAs($owner);
        $this->postJson('/txapi/orders/'.$order->trade_no.'/checkout')
            ->assertStatus(422)->assertJsonPath('error.code', 'PAYMENT_METHOD_INVALID');
        $order->update(['total_amount' => -100]);
        $this->postJson('/txapi/orders/'.$order->trade_no.'/checkout')
            ->assertStatus(409)->assertJsonPath('error.code', 'ORDER_CONFLICT');
    }

    private function user(string $email): User
    {
        return User::create([
            'email' => $email, 'password' => 'test-hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'banned' => 0,
        ]);
    }

    private function order(User $user, int $amount): Order
    {
        $plan = Plan::create([
            'name' => 'Checkout plan', 'group_id' => 1, 'show' => true,
            'sell' => true, 'renew' => true, 'capacity_limit' => null,
            'transfer_enable' => 2,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
        ]);
        return Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'trade_no' => 'check_'.bin2hex(random_bytes(8)),
            'period' => Plan::PERIOD_MONTHLY, 'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_PENDING, 'total_amount' => $amount,
            'balance_amount' => 0,
        ]);
    }
}
