<?php

namespace Tests\Feature\Txapi;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class TxapiAdminNativeLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['secure_path' => 'rotating_native_admin',
            'captcha_enable' => 0, 'password_limit_enable' => 0]);
    }

    public function test_native_administrator_login_bootstraps_secure_path_without_subscription_secret(): void
    {
        $admin = $this->account('native-signin-admin@example.test', true);
        $login = $this->postJson('/txapi/auth/admin/login', [
            'email' => $admin->email, 'password' => 'SignInPassword2026',
        ])->assertOk()->assertJsonPath('data.is_admin', true)
            ->assertJsonPath('data.secure_path', 'rotating_native_admin')
            ->assertJsonStructure(['request_id', 'data' => ['auth_data']]);
        $this->assertSame(['auth_data', 'is_admin', 'secure_path'], array_keys($login->json('data')));
        $this->assertStringStartsWith('Bearer ', $login->json('data.auth_data'));
        $this->assertStringNotContainsString($admin->token, $login->getContent());
        $this->assertStringContainsString('no-store', (string) $login->headers->get('Cache-Control'));
        $this->getJson('/txapi/admin/rotating_native_admin/modules', [
            'Authorization' => $login->json('data.auth_data'),
        ])->assertOk();
        $this->getJson('/txapi/admin/wrong/modules', [
            'Authorization' => $login->json('data.auth_data'),
        ])->assertNotFound();
    }

    public function test_non_admin_wrong_password_and_unknown_account_cannot_get_admin_token(): void
    {
        $ordinary = $this->account('native-signin-ordinary@example.test');
        $this->postJson('/txapi/auth/admin/login', [
            'email' => $ordinary->email, 'password' => 'SignInPassword2026',
        ])->assertStatus(403)->assertJsonPath('error.code', 'ADMIN_ACCESS_DENIED');
        $this->assertSame(0, $ordinary->tokens()->count());
        $this->postJson('/txapi/auth/admin/login', [
            'email' => $ordinary->email, 'password' => 'WrongPassword',
        ])->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $this->postJson('/txapi/auth/admin/login', [
            'email' => 'unknown@example.test', 'password' => 'WrongPassword',
        ])->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $this->assertSame(0, $ordinary->tokens()->count());
    }

    public function test_native_captcha_config_matches_public_site_projection_and_never_exposes_secret(): void
    {
        admin_setting(['captcha_enable' => 1, 'captcha_type' => 'turnstile',
            'turnstile_site_key' => 'visible-test-site-key']);
        $response = $this->getJson('/txapi/public/site-config')->assertOk()
            ->assertJsonPath('data.is_captcha', 1)
            ->assertJsonPath('data.captcha_type', 'turnstile')
            ->assertJsonPath('data.turnstile_site_key', 'visible-test-site-key');
        $this->assertArrayNotHasKey('secure_path', $response->json('data'));
        $this->assertArrayNotHasKey('telegram_bot_token', $response->json('data'));
    }

    private function account(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email, 'password' => Hash::make('SignInPassword2026'),
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'is_admin' => $admin,
            'banned' => false,
        ]);
    }
}
