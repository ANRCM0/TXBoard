<?php

namespace Tests\Feature\Txapi;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiSiteConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_config_matches_legacy_features_and_omits_secrets(): void
    {
        $native = $this->getJson('/txapi/public/site-config');
        $legacy = $this->getJson('/api/v1/guest/comm/config');
        $native->assertOk()->assertJsonStructure(['data', 'request_id']);
        $legacy->assertOk();
        $this->assertSame($legacy->json('data'), $native->json('data'));
        foreach (['telegram_bot_token', 'app_key', 'stripe_sk', 'jwt_secret'] as $key) {
            $this->assertArrayNotHasKey($key, $native->json('data'));
        }
    }

    public function test_user_config_uses_sanctum_and_matches_same_legacy_projection(): void
    {
        $this->getJson('/txapi/me/site-config')->assertStatus(401);
        $user = User::create([
            'email' => 'site-config-owner@example.test', 'password' => 'hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'banned' => false,
        ]);
        Sanctum::actingAs($user);
        $native = $this->getJson('/txapi/me/site-config');
        $legacy = $this->getJson('/api/v1/user/comm/config');
        $native->assertOk();
        $legacy->assertOk();
        $this->assertSame($legacy->json('data'), $native->json('data'));
        $this->assertArrayNotHasKey('telegram_bot_token', $native->json('data'));
    }
}
