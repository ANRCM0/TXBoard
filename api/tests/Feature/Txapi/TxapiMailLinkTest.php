<?php

namespace Tests\Feature\Txapi;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiMailLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        admin_setting(['login_with_mail_link_enable' => 1, 'captcha_enable' => 0]);
        Queue::fake();
    }

    public function test_mail_link_uses_shared_service_and_does_not_reveal_account_existence(): void
    {
        $user = $this->user('mail-owner@example.test');
        $this->postJson('/txapi/auth/mail-link', ['email' => $user->email])
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->postJson('/txapi/auth/mail-link', ['email' => 'unknown@example.test'])
            ->assertOk()->assertJsonPath('data.ok', true);
        Queue::assertPushed(SendEmailJob::class, 1);
        $this->postJson('/txapi/auth/mail-link', ['email' => $user->email])
            ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_one_time_token_exchanges_once_and_never_exposes_subscription_or_admin_keys(): void
    {
        $user = $this->user('redeem@example.test');
        $code = bin2hex(random_bytes(16));
        Cache::put(CacheKey::get('TEMP_TOKEN', $code), $user->id, 300);
        $response = $this->postJson('/txapi/auth/one-time-token', ['verify' => $code]);
        $response->assertOk()->assertJsonStructure(['data' => ['auth_data'], 'request_id']);
        $this->assertSame(['auth_data'], array_keys($response->json('data')));
        $this->assertStringStartsWith('Bearer ', $response->json('data.auth_data'));
        $this->assertStringNotContainsString($user->token, $response->getContent());
        $this->assertStringNotContainsString('secure_path', $response->getContent());
        $this->postJson('/txapi/auth/one-time-token', ['verify' => $code])
            ->assertStatus(401)->assertJsonPath('error.code', 'TOKEN_INVALID');
        $headers = ['Authorization' => $response->json('data.auth_data')];
        app('auth')->forgetGuards();
        $this->getJson('/txapi/me', $headers)
            ->assertOk()->assertJsonPath('data.email', $user->email);
    }

    public function test_invalid_banned_and_expired_tokens_are_denied(): void
    {
        $user = $this->user('mail-banned@example.test');
        $user->update(['banned' => true]);
        $code = bin2hex(random_bytes(16));
        Cache::put(CacheKey::get('TEMP_TOKEN', $code), $user->id, 300);
        $this->postJson('/txapi/auth/one-time-token', ['verify' => $code])
            ->assertStatus(401)->assertJsonPath('error.code', 'TOKEN_INVALID');
        $this->postJson('/txapi/auth/one-time-token', ['verify' => 'missing'])
            ->assertStatus(401)->assertJsonPath('error.code', 'TOKEN_INVALID');
        $this->postJson('/txapi/auth/one-time-token', [])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    private function user(string $email): User
    {
        return User::create([
            'email' => $email, 'password' => 'dummy-password-hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'banned' => false,
        ]);
    }
}
