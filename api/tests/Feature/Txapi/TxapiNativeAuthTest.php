<?php

namespace Tests\Feature\Txapi;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TxapiNativeAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting([
            'captcha_enable' => 0,
            'stop_register' => 0,
            'email_verify' => 0,
            'invite_force' => 0,
            'try_out_plan_id' => 0,
        ]);
    }

    public function test_native_login_reuses_sanctum_without_exposing_subscription_token_or_admin_path(): void
    {
        $user = $this->user('auth-user@example.test');
        $login = $this->postJson('/txapi/auth/login', [
            'email' => $user->email, 'password' => 'P2StrongPassword2026',
        ]);
        $login->assertOk()->assertJsonStructure(['data' => ['auth_data'], 'request_id']);
        $this->assertStringStartsWith('Bearer ', $login->json('data.auth_data'));
        $this->assertSame(['auth_data'], array_keys($login->json('data')));
        $this->assertStringNotContainsString($user->token, $login->getContent());
        $this->assertStringNotContainsString('secure_path', $login->getContent());

        $headers = ['Authorization' => $login->json('data.auth_data')];
        $this->getJson('/txapi/me', $headers)
            ->assertOk()->assertJsonPath('data.email', $user->email);
        $sessions = $this->getJson('/txapi/auth/sessions', $headers);
        $sessions->assertOk()->assertJsonCount(1, 'data');
        $this->assertArrayNotHasKey('token', $sessions->json('data.0'));
        $this->assertArrayNotHasKey('token_hash', $sessions->json('data.0'));

        $sessionId = $sessions->json('data.0.id');
        $this->deleteJson('/txapi/auth/sessions/'.$sessionId, [], $headers)
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->getJson('/txapi/me', $headers)->assertStatus(401);
    }

    public function test_native_login_rejects_invalid_password_with_uniform_error_and_no_token(): void
    {
        $this->user('invalid@example.test');
        $response = $this->postJson('/txapi/auth/login', [
            'email' => 'invalid@example.test', 'password' => 'IncorrectPassword',
        ]);
        $response->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $this->assertArrayNotHasKey('data', $response->json());
        $this->postJson('/txapi/auth/login', [
            'email' => 'nobody@example.test', 'password' => 'IncorrectPassword',
        ])->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_native_registration_obeys_existing_policy_and_can_log_in(): void
    {
        $register = $this->postJson('/txapi/auth/register', [
            'email' => 'new-native@example.test',
            'password' => 'P2NewPassword2026',
        ]);
        $register->assertStatus(201)->assertJsonStructure(['data' => ['auth_data']]);
        $this->assertSame(['auth_data'], array_keys($register->json('data')));
        $this->assertTrue(User::where('email', 'new-native@example.test')->exists());
        $this->getJson('/txapi/me', [
            'Authorization' => $register->json('data.auth_data'),
        ])->assertOk()->assertJsonPath('data.email', 'new-native@example.test');

        $duplicate = $this->postJson('/txapi/auth/register', [
            'email' => 'new-native@example.test',
            'password' => 'P2NewPassword2026',
        ]);
        $duplicate->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
    }

    public function test_native_password_change_checks_old_secret_and_invalidates_other_sessions(): void
    {
        $user = $this->user('pass-change@example.test');
        $first = $this->postJson('/txapi/auth/login', [
            'email' => $user->email, 'password' => 'P2StrongPassword2026',
        ])->json('data.auth_data');
        $second = $this->postJson('/txapi/auth/login', [
            'email' => $user->email, 'password' => 'P2StrongPassword2026',
        ])->json('data.auth_data');

        $this->postJson('/txapi/auth/password', [
            'old_password' => 'WrongOldSecret', 'new_password' => 'NewPassword2026',
        ], ['Authorization' => $second])->assertStatus(400);
        $this->postJson('/txapi/auth/password', [
            'old_password' => 'P2StrongPassword2026', 'new_password' => 'NewPassword2026',
        ], ['Authorization' => $second])->assertOk()->assertJsonPath('data.ok', true);
        $this->getJson('/txapi/me', ['Authorization' => $first])->assertStatus(401);
        $this->getJson('/txapi/me', ['Authorization' => $second])->assertOk();
        $this->assertTrue(Hash::check('NewPassword2026', $user->fresh()->password));
    }

    private function user(string $email): User
    {
        return User::create([
            'email' => $email,
            'password' => Hash::make('P2StrongPassword2026'),
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'banned' => false, 'is_admin' => false,
        ]);
    }
}
