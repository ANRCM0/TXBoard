<?php

namespace Tests\Feature\Txapi;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiAdminCommerceReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_with_correct_dynamic_path_can_read_native_catalog_and_orders(): void
    {
        admin_setting(['secure_path' => 'native_merchant_admin']);
        $admin = $this->user('admin-commerce@example.test', true);
        $reader = $this->user('nonadmin-commerce@example.test', false);
        $this->getJson('/txapi/admin/native_merchant_admin/orders')->assertStatus(403);
        Sanctum::actingAs($reader);
        $this->getJson('/txapi/admin/native_merchant_admin/plans')->assertStatus(403);
        $this->getJson('/txapi/admin/native_merchant_admin/orders')->assertStatus(403);
        Sanctum::actingAs($admin);
        $this->getJson('/txapi/admin/guessed/orders')->assertStatus(404);
        $this->getJson('/txapi/admin/native_merchant_admin/plans')->assertOk();
        $this->getJson('/txapi/admin/native_merchant_admin/orders')->assertOk();
        admin_setting(['secure_path' => 'native_merchant_rotated']);
        $this->getJson('/txapi/admin/native_merchant_admin/plans')->assertStatus(404);
        $this->getJson('/txapi/admin/native_merchant_rotated/plans')->assertOk();
    }

    public function test_plan_catalog_includes_hidden_plans_counts_and_paginated_safe_fields(): void
    {
        admin_setting(['secure_path' => 'catalog_only']);
        Sanctum::actingAs($this->user('catalog-admin@example.test', true));
        $visible = $this->plan('Visible plan', true);
        $hidden = $this->plan('Hidden plan', false);
        $this->user('subscriber-catalog@example.test', false, ['plan_id' => $visible->id, 'expired_at' => time() + 3600]);

        $response = $this->getJson('/txapi/admin/catalog_only/plans?page=1&per_page=1');
        $response->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.users_count', 1)
            ->assertJsonPath('data.0.active_users_count', 1)
            ->assertJsonPath('data.0.prices.monthly', 10);
        $this->getJson('/txapi/admin/catalog_only/plans?page=2&per_page=1')
            ->assertOk()->assertJsonPath('data.0.id', $hidden->id)
            ->assertJsonPath('data.0.show', false);
        $this->getJson('/txapi/admin/catalog_only/plans?per_page=101')
            ->assertStatus(422);
        $this->assertStringNotContainsString('token', $response->getContent());
    }

    public function test_order_filters_are_whitelisted_paginated_and_do_not_expose_account_credentials(): void
    {
        admin_setting(['secure_path' => 'order_reporting']);
        $admin = $this->user('orders-admin@example.test', true);
        $owner = $this->user('orders-owner@example.test', false);
        $other = $this->user('else-owner@example.test', false);
        $plan = $this->plan('Order plan', true);
        Order::create([
            'user_id' => $owner->id, 'plan_id' => $plan->id,
            'trade_no' => 'TXCOMMERCE-1001', 'period' => Plan::PERIOD_MONTHLY,
            'type' => Order::TYPE_NEW_PURCHASE, 'status' => Order::STATUS_COMPLETED,
            'total_amount' => 1050, 'commission_status' => 1,
            'commission_balance' => 80, 'invite_user_id' => $admin->id,
            'callback_no' => 'reference-8811',
        ]);
        Order::create([
            'user_id' => $other->id, 'plan_id' => $plan->id,
            'trade_no' => 'TXCOMMERCE-2002', 'period' => Plan::PERIOD_MONTHLY,
            'type' => Order::TYPE_NEW_PURCHASE, 'status' => Order::STATUS_PENDING,
            'total_amount' => 2200, 'commission_status' => 0,
        ]);
        Sanctum::actingAs($admin);
        $base = '/txapi/admin/order_reporting/orders';
        $res = $this->getJson($base . '?email=orders-owner&status=3&commission_status=1&is_commission=1');
        $res->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.trade_no', 'TXCOMMERCE-1001')
            ->assertJsonPath('data.0.total_amount', 1050)
            ->assertJsonPath('data.0.user.email', 'orders-owner@example.test')
            ->assertJsonPath('data.0.period', 'month_price');
        $this->assertStringNotContainsString($owner->token, $res->getContent());
        $this->assertStringNotContainsString('password', $res->getContent());
        $this->getJson($base . '?page=1&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->getJson($base . '?user_id=' . $other->id)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.trade_no', 'TXCOMMERCE-2002');
        foreach (['per_page=101','page=0','status=8','commission_status=9','user_id=nan'] as $query) {
            $this->getJson($base . '?' . $query)->assertStatus(422);
        }
    }

    private function user(string $email, bool $admin, array $more = []): User
    {
        return User::create(array_merge([
            'email' => $email, 'password' => 'never-disclose-password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => $admin, 'balance' => 0, 'commission_balance' => 0, 'banned' => 0,
        ], $more));
    }

    private function plan(string $name, bool $visible): Plan
    {
        return Plan::create([
            'name' => $name, 'group_id' => 1,
            'transfer_enable' => 2, 'show' => $visible,
            'sell' => true, 'renew' => true, 'sort' => $visible ? 1 : 2,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
        ]);
    }
}
