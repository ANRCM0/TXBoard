<?php

namespace Tests\Feature\Txapi;

use App\Models\User;
use App\Services\Auth\MailLinkService;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiSecurityActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Authentication and DTO tests must not share IP-rate state with other
        // features in the same suite. Middleware remains enabled in production.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    public function test_quick_login_requires_user_token_validates_redirect_and_uses_one_time_login_service(): void
    {
        $this->postJson('/txapi/auth/quick-login')->assertStatus(401);
        $this->postJson('/txapi/me/subscription-credentials/rotate')->assertStatus(401);
        $user = $this->user('security-owner@example.test');
        Sanctum::actingAs($user);
        foreach (['https://example.net', '//example.net', '../profile', '/profile?next=https://evil.net'] as $redirect) {
            $this->postJson('/txapi/auth/quick-login', ['redirect' => $redirect])
                ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_REDIRECT');
        }
        $response = $this->postJson('/txapi/auth/quick-login', ['redirect' => 'dashboard']);
        $response->assertOk();
        $url = (string) $response->json('data.url');
        $this->assertStringContainsString('/#/login?verify=', $url);
        $this->assertStringContainsString('redirect=dashboard', $url);
        $this->assertArrayNotHasKey('token', $response->json('data'));
        preg_match('/[?&]verify=([^&]+)/', $url, $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $token = urldecode($matches[1]);
        $this->assertSame($user->id, app(MailLinkService::class)->handleTokenLogin($token));
        $this->assertNull(app(MailLinkService::class)->handleTokenLogin($token));
        $this->assertNull(Cache::get(CacheKey::get('TEMP_TOKEN', $token)));
    }

    public function test_rotation_changes_only_current_users_subscription_credential(): void
    {
        $owner = $this->user('rotate-owner@example.test');
        $other = $this->user('rotate-other@example.test');
        $old = $owner->token;
        $uuid = $owner->uuid;
        $unrelatedToken = $other->token;
        Sanctum::actingAs($owner);
        $this->getJson('/txapi/me/subscription-credentials/rotate')->assertStatus(404);
        $result = $this->postJson('/txapi/me/subscription-credentials/rotate');
        $result->assertOk()->assertJsonStructure(['data' => ['subscribe_url'], 'request_id']);
        $owner->refresh();
        $this->assertNotSame($old, $owner->token);
        $this->assertNotSame($uuid, $owner->uuid);
        $this->assertSame($unrelatedToken, $other->fresh()->token);
        $this->assertStringContainsString($owner->token, (string) $result->json('data.subscribe_url'));
        $this->assertArrayNotHasKey('token', $result->json('data'));
    }

    private function user(string $email): User
    {
        return User::create([
            'email' => $email, 'password' => 'hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'banned' => false,
        ]);
    }
}
