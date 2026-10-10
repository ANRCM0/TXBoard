<?php

namespace Tests\Feature\Txapi;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiAdminUserReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_directory_and_secret_lookup_require_admin_and_valid_secure_path(): void
    {
        admin_setting(['secure_path' => 'users_admin_secret']);
        $normal = $this->user('native-standard@example.test', false);
        $admin = $this->user('native-root@example.test', true);
        $url = '/txapi/admin/users_admin_secret/users';
        $this->getJson($url)->assertStatus(403);
        Sanctum::actingAs($normal);
        $this->getJson($url)->assertStatus(403);
        $this->getJson($url . '/' . $admin->id . '/subscription-link')->assertStatus(403);
        Sanctum::actingAs($admin);
        $this->getJson('/txapi/admin/guessed/users')->assertStatus(404);
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_native_directory_filters_sorting_and_money_are_safe_and_paginated(): void
    {
        admin_setting(['secure_path' => 'users_native']);
        $admin = $this->user('native-list-admin@example.test', true);
        $plan = Plan::create([
            'name' => 'Native Users Plan', 'group_id' => 1, 'show' => true,
            'sell' => true, 'renew' => true, 'transfer_enable' => 2,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
        ]);
        $user = $this->user('native-filter-user@example.test', false, [
            'plan_id' => $plan->id, 'balance' => 1234, 'commission_balance' => 567,
            'banned' => false, 'u' => 25, 'd' => 75,
        ]);
        $this->user('other-user@example.test', false, ['banned' => true]);
        Sanctum::actingAs($admin);
        $root = '/txapi/admin/users_native/users';
        $res = $this->getJson($root . '?email=native-filter&plan_id=' . $plan->id .
            '&banned=0&sort=total_used&descending=1');
        $res->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $user->id)
            ->assertJsonPath('data.0.plan.name', 'Native Users Plan')
            ->assertJsonPath('data.0.balance', 12.34)
            ->assertJsonPath('data.0.commission_balance', 5.67)
            ->assertJsonPath('data.0.total_used', 100);
        $this->assertStringNotContainsString((string) $user->token, $res->getContent());
        $this->assertStringNotContainsString('subscribe_url', $res->getContent());
        $this->assertStringNotContainsString('password', $res->getContent());

        $this->getJson($root . '/' . $user->id)->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.balance', 12.34)
            ->assertJsonMissingPath('data.token');
        $secret = $this->getJson($root . '/' . $user->id . '/subscription-link');
        $secret->assertOk()->assertJsonStructure(['data' => ['subscribe_url'], 'request_id']);
        $this->assertStringContainsString($user->token, (string) $secret->json('data.subscribe_url'));

        $this->getJson($root . '?page=1&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 3);
        foreach (['page=0', 'per_page=101', 'sort=sql%3Araw', 'plan_id=abc', 'banned=9'] as $bad) {
            $this->getJson($root . '?' . $bad)->assertStatus(422);
        }
        $this->getJson($root . '/999999')->assertStatus(404);
        $this->getJson($root . '/999999/subscription-link')->assertStatus(404);
    }

    private function user(string $email, bool $admin, array $more = []): User
    {
        return User::create(array_merge([
            'email' => $email, 'password' => 'secret-not-exposed',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => $admin, 'banned' => 0, 'balance' => 0,
            'commission_balance' => 0,
        ], $more));
    }
}
