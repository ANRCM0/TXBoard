<?php

namespace Tests\Feature\Txapi;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiOrderDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_requires_auth_and_owner_scope_and_has_fixed_financial_fields(): void
    {
        $owner = $this->user('detail-owner@example.test');
        $other = $this->user('detail-other@example.test');
        $plan = Plan::create([
            'name' => 'Detail Plan', 'group_id' => 1, 'transfer_enable' => 3,
            'show' => true, 'sell' => true, 'renew' => true, 'capacity_limit' => null,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'prices' => [Plan::PERIOD_MONTHLY => 12],
        ]);
        $order = Order::create([
            'user_id' => $owner->id, 'plan_id' => $plan->id,
            'trade_no' => 'detail-trade-1', 'period' => Plan::PERIOD_MONTHLY,
            'type' => Order::TYPE_NEW_PURCHASE, 'status' => Order::STATUS_PENDING,
            'total_amount' => 650, 'balance_amount' => 350, 'discount_amount' => 200,
            'callback_no' => 'private-provider-callback-id',
        ]);
        $url = '/txapi/orders/' . $order->trade_no . '/detail';
        $this->getJson($url)->assertStatus(401);
        Sanctum::actingAs($other);
        $this->getJson($url)->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
        Sanctum::actingAs($owner);
        $response = $this->getJson($url);
        $response->assertOk()
            ->assertJsonPath('data.balance_amount_minor', 350)
            ->assertJsonPath('data.discount_amount_minor', 200)
            ->assertJsonPath('data.payment_id', null)
            ->assertJsonPath('data.amount_minor', 650)
            ->assertJsonPath('data.plan.traffic_limit_bytes', 3 * 1073741824);
        $fields = array_keys($response->json('data'));
        foreach (['user_id', 'callback_no', 'surplus_order_ids', 'token', 'payment', 'commission_balance'] as $field) {
            $this->assertNotContains($field, $fields);
        }
        $this->assertStringNotContainsString('private-provider-callback-id', $response->getContent());
        $summary = $this->getJson('/txapi/orders/' . $order->trade_no);
        $summary->assertOk();
        $this->assertArrayNotHasKey('balance_amount_minor', $summary->json('data'));
        $this->getJson('/txapi/orders/missing/detail')->assertStatus(404);
    }

    private function user(string $email): User
    {
        return User::create([
            'email' => $email, 'password' => 'test-hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'banned' => 0,
        ]);
    }
}
