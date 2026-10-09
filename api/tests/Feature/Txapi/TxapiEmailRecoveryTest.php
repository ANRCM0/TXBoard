<?php

namespace Tests\Feature\Txapi;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TxapiEmailRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        admin_setting(['captcha_enable' => 0, 'email_whitelist_enable' => 0]);
        Queue::fake();
    }

    public function test_native_and_legacy_codes_share_cache_cooldown_and_delivery(): void
    {
        $email = 'verify@example.test';
        $res = $this->postJson('/txapi/auth/email-code', ['email' => $email, 'purpose' => 'forget']);
        $res->assertOk()->assertJsonPath('data.ok', true);
        $this->assertArrayNotHasKey('code', $res->json('data'));
        $code = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $email));
        $this->assertIsInt($code);
        $this->assertGreaterThanOrEqual(100000, $code);
        $this->assertLessThanOrEqual(999999, $code);
        $this->postJson('/txapi/auth/email-code', ['email' => $email])
            ->assertStatus(400)->assertJsonPath('error.code', 'EMAIL_CODE_REJECTED');
        $legacy = 'legacy-verify@example.test';
        $this->postJson('/api/v1/passport/comm/sendEmailVerify', ['email' => $legacy])->assertOk();
        $this->assertNotNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $legacy)));
        Queue::assertPushed(SendEmailJob::class, 2);
    }

    public function test_password_recovery_is_one_time_and_revokes_old_sessions(): void
    {
        $user = $this->user('reset@example.test');
        $old = $user->createToken('prior-session')->plainTextToken;
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $user->email), 123456, 300);
        $url = '/txapi/auth/password/forgot';
        $this->postJson($url, [
            'email' => $user->email, 'email_code' => '000000', 'password' => 'NewPassword2026',
        ])->assertStatus(400)->assertJsonPath('error.code', 'RESET_REJECTED');
        $this->postJson($url, [
            'email' => $user->email, 'email_code' => '123456', 'password' => 'NewPassword2026',
        ])->assertOk()->assertJsonPath('data.ok', true);
        $this->assertTrue(Hash::check('NewPassword2026', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $user->email)));
        app('auth')->forgetGuards();
        $this->getJson('/txapi/me', ['Authorization' => 'Bearer '.$old])->assertStatus(401);
        $this->postJson($url, [
            'email' => $user->email, 'email_code' => '123456', 'password' => 'AnotherPassword2026',
        ])->assertStatus(400);
    }

    public function test_invalid_recovery_fields_are_rejected(): void
    {
        $this->postJson('/txapi/auth/password/forgot', [
            'email' => 'bad', 'email_code' => '12', 'password' => 'short',
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->postJson('/txapi/auth/email-code', ['email' => 'bad'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    private function user(string $email): User
    {
        return User::create([
            'email' => $email, 'password' => Hash::make('OldPassword2026'),
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'banned' => false,
        ]);
    }
}
