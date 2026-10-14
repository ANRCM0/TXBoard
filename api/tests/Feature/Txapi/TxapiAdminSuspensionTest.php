<?php

namespace Tests\Feature\Txapi;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class TxapiAdminSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_PATH = '/txapi/admin/suspension_test_admin/modules';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        admin_setting(['secure_path' => 'suspension_test_admin',
            'captcha_enable' => 0, 'password_limit_enable' => 0]);
    }

    public function test_existing_admin_bearer_loses_access_on_ban_and_regains_it_only_after_current_ban_is_removed(): void
    {
        $admin = $this->account();
        $bearer = $this->login($admin);
        $this->adminRequest($bearer)->assertOk();
        $token = $admin->tokens()->sole();
        $tokenHash = $token->token;

        DB::table('tx_user')->where('id', $admin->id)->update(['banned' => true]);
        $this->adminRequest($bearer)->assertForbidden()->assertExactJson(['message' => 'Unauthorized']);
        Auth::forgetGuards();
        $this->getJson('/txapi/me', ['Authorization' => $bearer])->assertForbidden();
        Auth::forgetGuards();
        $this->getJson('/txapi/public/site-config', ['Authorization' => $bearer])->assertOk();
        $this->assertSame($tokenHash, $token->fresh()->token);
        $this->assertSame(1, $admin->tokens()->count());
        $this->assertTrue($admin->fresh()->is_admin);

        DB::table('tx_user')->where('id', $admin->id)->update(['banned' => false]);
        $this->adminRequest($bearer)->assertOk();
        $this->assertSame($tokenHash, $token->fresh()->token);
    }

    public function test_revoked_admin_role_is_checked_again_for_an_existing_bearer(): void
    {
        $admin = $this->account();
        $bearer = $this->login($admin);
        $this->adminRequest($bearer)->assertOk();
        DB::table('tx_user')->where('id', $admin->id)->update(['is_admin' => false]);
        $this->adminRequest($bearer)->assertForbidden()->assertExactJson(['message' => 'Unauthorized']);
        Auth::forgetGuards();
        $this->getJson('/txapi/me', ['Authorization' => $bearer])->assertOk();
        $this->assertSame(1, $admin->tokens()->count());
    }

    public function test_suspension_does_not_reveal_the_administrator_path(): void
    {
        $admin = $this->account();
        $bearer = $this->login($admin);
        DB::table('tx_user')->where('id', $admin->id)->update(['banned' => true]);
        Auth::forgetGuards();
        $this->getJson('/txapi/admin/wrong_path/modules', ['Authorization' => $bearer])->assertNotFound();
        $this->adminRequest($bearer)->assertForbidden();
    }

    public function test_expired_deleted_invalid_and_retired_agent_bearers_cannot_enter_admin_routes(): void
    {
        $admin = $this->account();
        foreach ([['expired', ['*'], now()->subMinute()],
            ['agent:retired', ['*'], now()->addHour()],
            ['ordinary', ['agent:read'], now()->addHour()]] as [$name, $abilities, $expires]) {
            $token = $admin->createToken($name, $abilities, $expires);
            $this->adminRequest('Bearer ' . $token->plainTextToken)->assertForbidden();
            $this->assertNull($token->accessToken->fresh()->last_used_at);
        }
        $deleted = $admin->createToken('deleted', ['*'], now()->addHour());
        $deleted->accessToken->delete();
        $this->adminRequest('Bearer ' . $deleted->plainTextToken)->assertForbidden();
        $this->adminRequest('Bearer invalid-suspension-test-token')->assertForbidden();
    }

    public function test_banned_administrator_cannot_issue_a_new_admin_or_user_bearer(): void
    {
        $admin = $this->account();
        $bearer = $this->login($admin);
        DB::table('tx_user')->where('id', $admin->id)->update(['banned' => true]);
        foreach (['/txapi/auth/admin/login', '/txapi/auth/login'] as $path) {
            Auth::forgetGuards();
            $this->postJson($path, ['email' => $admin->email, 'password' => 'SuspensionPassword2026'])
                ->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        }
        $this->assertSame(1, $admin->tokens()->count());
        $this->adminRequest($bearer)->assertForbidden();
    }

    private function login(User $admin): string
    {
        Auth::forgetGuards();
        $response = $this->postJson('/txapi/auth/admin/login', [
            'email' => $admin->email, 'password' => 'SuspensionPassword2026',
        ])->assertOk()->assertJsonPath('data.is_admin', true);
        return $response->json('data.auth_data');
    }

    private function adminRequest(string $bearer): \Illuminate\Testing\TestResponse
    {
        Auth::forgetGuards();
        return $this->getJson(self::ADMIN_PATH, ['Authorization' => $bearer]);
    }

    private function account(): User
    {
        return User::create(['email' => 'suspension-admin@example.test',
            'password' => Hash::make('SuspensionPassword2026'),
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'is_admin' => true, 'banned' => false]);
    }
}
