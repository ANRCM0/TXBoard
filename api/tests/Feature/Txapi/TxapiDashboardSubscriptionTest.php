<?php

namespace Tests\Feature\Txapi;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiDashboardSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_and_subscription_require_auth_and_return_scoped_safe_fields(): void
    {
        $this->getJson('/txapi/me/subscription')->assertStatus(401);
        $this->getJson('/txapi/me/dashboard-stats')->assertStatus(401);
        $owner = $this->user('sub-owner@example.test');
        $other = $this->user('sub-other@example.test');
        $invited = $this->user('sub-invited@example.test');
        $invited->invite_user_id = $owner->id;
        $invited->save();
        Sanctum::actingAs($owner);
        $sub = $this->getJson('/txapi/me/subscription');
        $sub->assertOk()->assertJsonPath('data.upload_bytes', 111)
            ->assertJsonPath('data.download_bytes', 222)
            ->assertJsonPath('data.traffic_limit_bytes', 10000)
            ->assertJsonPath('data.plan', null);
        $this->assertStringContainsString($owner->token, $sub->json('data.subscribe_url'));
        $this->assertStringNotContainsString($other->token, $sub->getContent());
        $this->assertArrayNotHasKey('token', $sub->json('data'));
        $this->assertArrayNotHasKey('uuid', $sub->json('data'));
        $this->getJson('/txapi/me/dashboard-stats')->assertOk()
            ->assertJsonPath('data.unpaid_orders', 0)
            ->assertJsonPath('data.open_tickets', 0)
            ->assertJsonPath('data.invited_users', 1);
        Sanctum::actingAs($other);
        $this->getJson('/txapi/me/dashboard-stats')->assertOk()
            ->assertJsonPath('data.invited_users', 0);
    }

    private function user(string $email): User
    {
        return User::create([
            'email' => $email, 'password' => Hash::make('test-password'),
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'u' => 111, 'd' => 222, 'transfer_enable' => 10000,
            'banned' => false,
        ]);
    }
}
