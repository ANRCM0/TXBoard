<?php

namespace Tests\Feature\Txapi;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiCoreContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_liveness_is_isolated_and_request_id_cannot_be_supplied_by_client(): void
    {
        $response = $this->withHeader('X-Request-Id', 'caller-chosen-value')
            ->getJson('/txapi/health');
        $response->assertOk()->assertJsonPath('data.status', 'ok')
            ->assertJsonMissingPath('status');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $response->json('request_id')
        );
        $this->assertSame($response->json('request_id'),
            $response->headers->get('X-Request-Id'));
        $this->assertNotSame('caller-chosen-value', $response->json('request_id'));

        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
        $this->assertNotNull(Route::getRoutes()->match(
            \Illuminate\Http\Request::create('/api/v1/guest/plan/fetch', 'GET')));
        $this->assertNotNull(Route::getRoutes()->match(
            \Illuminate\Http\Request::create('/api/v1/guest/payment/notify/EPay/example', 'POST')));
    }

    public function test_public_config_does_not_expose_admin_path_or_keys(): void
    {
        $response = $this->getJson('/txapi/public/config');
        $response->assertOk()->assertJsonPath('data.api_prefix', '/txapi');
        $this->assertSame(['name', 'api_prefix'], array_keys($response->json('data')));
        $this->assertArrayNotHasKey('status', $response->json());
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-Id'));
    }

    public function test_public_plans_are_whitelisted_and_use_native_period_and_minor_units(): void
    {
        $visible = $this->plan('Visible', true, true, null);
        $this->plan('Hidden', false, true, null);
        $this->plan('Disabled', true, false, null);
        $this->plan('Sold out', true, true, 0);

        $response = $this->getJson('/txapi/plans');
        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.prices.0.period', Plan::PERIOD_MONTHLY)
            ->assertJsonPath('data.0.prices.0.amount_minor', 1250)
            ->assertJsonPath('data.0.traffic_limit_bytes', 2 * 1073741824);
        $this->assertArrayNotHasKey('month_price', $response->json('data.0'));
        $this->assertArrayNotHasKey('group_id', $response->json('data.0'));
        $this->assertArrayNotHasKey('password', $response->json('data.0'));
    }

    public function test_user_routes_reject_missing_non_user_and_banned_credentials(): void
    {
        $this->getJson('/txapi/me')->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->getJson('/txapi/orders')->assertStatus(401);
        $this->withToken('not-a-valid-sanctum-user-token')->getJson('/txapi/me')
            ->assertStatus(401);

        $blocked = $this->user('banned@example.test', true);
        Sanctum::actingAs($blocked);
        $this->getJson('/txapi/me')->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_me_returns_only_safe_fields(): void
    {
        $user = $this->user('self@example.test');
        $user->update(['u' => 21, 'd' => 13, 'transfer_enable' => 100]);
        Sanctum::actingAs($user);
        $response = $this->getJson('/txapi/me');
        $response->assertOk()->assertJsonPath('data.email', 'self@example.test')
            ->assertJsonPath('data.traffic.upload_bytes', 21);
        $this->assertSame(['id', 'email', 'plan_id', 'traffic'], array_keys($response->json('data')));
        $this->assertStringNotContainsString($user->token, $response->getContent());
        $this->assertStringNotContainsString($user->password, $response->getContent());
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-Id'));
    }

    public function test_orders_are_paginated_sorted_and_restricted_to_owner(): void
    {
        $owner = $this->user('owner@example.test');
        $other = $this->user('other@example.test');
        $plan = $this->plan('Order plan', true, true, null);
        $first = $this->order($owner, $plan, 'mine-01', 100);
        $second = $this->order($owner, $plan, 'mine-02', 100);
        $outsider = $this->order($other, $plan, 'other-01', 100);
        Sanctum::actingAs($owner);

        $page = $this->getJson('/txapi/orders?per_page=1&page=1');
        $page->assertOk()->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.trade_no', $second->trade_no);
        $this->assertArrayNotHasKey('callback_no', $page->json('data.0'));
        $this->assertSame(100, $page->json('data.0.amount_minor'));

        $this->getJson('/txapi/orders?per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.0.trade_no', $first->trade_no);

        $this->getJson('/txapi/orders/' . $first->trade_no)
            ->assertOk()->assertJsonPath('data.trade_no', $first->trade_no);
        $this->getJson('/txapi/orders/' . $outsider->trade_no)
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
        $this->getJson('/txapi/orders/no-such-trade')
            ->assertStatus(404);
    }

    public function test_invalid_parameters_have_safe_error_envelopes(): void
    {
        Sanctum::actingAs($this->user('invalid@example.test'));
        foreach (['per_page=0', 'per_page=101', 'per_page=hello',
            'page=-1', 'status=99', 'status=%3Cscript%3E'] as $query) {
            $response = $this->getJson('/txapi/orders?' . $query);
            $response->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED');
            $this->assertArrayNotHasKey('status', $response->json());
            $this->assertArrayHasKey('request_id', $response->json());
            $this->assertStringNotContainsString('<script>', $response->getContent());
        }
        $this->postJson('/txapi/me')->assertStatus(405)
            ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
        $this->getJson('/txapi/this-route-does-not-exist')->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    private function user(string $email, bool $banned = false): User
    {
        return User::create([
            'email' => $email, 'password' => 'never-expose-this-hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => str_repeat('a', 32),
            'u' => 0, 'd' => 0, 'transfer_enable' => 0,
            'banned' => $banned,
            'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    private function plan(string $name, bool $show, bool $sell, ?int $capacity): Plan
    {
        return Plan::create([
            'name' => $name, 'show' => $show, 'sell' => $sell, 'renew' => true,
            'group_id' => 1, 'transfer_enable' => 2,
            'sort' => 0, 'capacity_limit' => $capacity,
            'prices' => [Plan::PERIOD_MONTHLY => 12.5],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    private function order(User $user, Plan $plan, string $trade, int $amount): Order
    {
        return Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'trade_no' => $trade, 'period' => Plan::PERIOD_MONTHLY,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED, 'total_amount' => $amount,
            'created_at' => time(), 'updated_at' => time(),
        ]);
    }
}
