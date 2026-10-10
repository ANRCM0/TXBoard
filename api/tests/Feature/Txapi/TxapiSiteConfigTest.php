<?php

namespace Tests\Feature\Txapi;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiSiteConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_config_matches_authoritative_service_and_omits_secrets(): void
    {
        $native = $this->getJson('/txapi/public/site-config');
        $native->assertOk()->assertJsonStructure(['data', 'request_id']);
        $this->assertSame(app(\App\Services\SiteConfigService::class)->guest(), $native->json('data'));
        foreach (['telegram_bot_token', 'app_key', 'stripe_sk', 'jwt_secret'] as $key) {
            $this->assertArrayNotHasKey($key, $native->json('data'));
        }
    }

    public function test_user_config_uses_sanctum_and_matches_authoritative_service(): void
    {
        $this->getJson('/txapi/me/site-config')->assertStatus(401);
        $user = User::create([
            'email' => 'site-config-owner@example.test', 'password' => 'hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'banned' => false,
        ]);
        Sanctum::actingAs($user);
        $native = $this->getJson('/txapi/me/site-config');
        $native->assertOk();
        // JSON transport normalizes whole-number floating settings (100.0 -> 100).
        $this->assertEquals(app(\App\Services\SiteConfigService::class)->user(), $native->json('data'));
        $this->assertArrayNotHasKey('telegram_bot_token', $native->json('data'));
    }
}
