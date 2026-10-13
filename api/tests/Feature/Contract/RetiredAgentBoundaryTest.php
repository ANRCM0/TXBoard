<?php

namespace Tests\Feature\Contract;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class RetiredAgentBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_routes_and_commands_are_absent_and_requests_do_not_mutate_history(): void
    {
        $admin = $this->admin();
        $path = (string) admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $token = $admin->createToken('normal-admin', ['*'])->plainTextToken;
        DB::table('tx_agent_audit_log')->insert([
            'request_id' => 'retained-history', 'admin_id' => $admin->id,
            'tool' => 'historical-read', 'risk_level' => 'read', 'result_status' => 'succeeded',
            'created_at' => 1, 'updated_at' => 1,
        ]);
        $before = DB::table('tx_agent_audit_log')->get()->toJson();
        foreach (Route::getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('~^txapi/(?:agent/|admin/[^/]+/agents(?:/|$))~', $route->uri());
        }
        $this->assertArrayNotHasKey('agent:inspect-fleet', Artisan::all());
        foreach (['/txapi/agent/v1/whoami', '/txapi/agent/v1/pairings/redeem',
            "/txapi/admin/{$path}/agents/tokens", '/.well-known/txboard-agent-connect.md'] as $uri) {
            Auth::forgetGuards();
            $this->withToken($token)->getJson($uri)->assertNotFound();
            // Laravel's SPA fallback can reject missing POST paths with either
            // 404 or 405. Neither status registers or executes an Agent handler.
            $response = $this->withToken($token)->postJson($uri, []);
            $this->assertContains($response->status(), [404, 405], $uri);
        }
        $this->assertSame($before, DB::table('tx_agent_audit_log')->get()->toJson());
        $this->assertDatabaseCount('tx_agent_action', 0);
    }

    public function test_old_agent_tokens_cannot_authenticate_as_admin_or_user_even_after_renaming(): void
    {
        $admin = $this->admin();
        $path = (string) admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $tokens = [
            $admin->createToken('agent:old-client', ['*']),
            $admin->createToken('renamed-old-client', ['agent:nodes:read']),
        ];
        foreach ($tokens as $token) {
            Auth::forgetGuards();
            $this->withToken($token->plainTextToken)->getJson('/txapi/me')->assertUnauthorized();
            Auth::forgetGuards();
            $this->withToken($token->plainTextToken)->getJson("/txapi/admin/{$path}/settings")->assertForbidden();
            $this->assertNull($token->accessToken->fresh()->last_used_at);
        }
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_normal_admin_tokens_and_original_expiration_policy_still_apply(): void
    {
        $admin = $this->admin();
        $path = (string) admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $normal = $admin->createToken('normal-admin', ['*']);
        $this->withToken($normal->plainTextToken)->getJson('/txapi/me')->assertOk();
        Auth::forgetGuards();
        $this->withToken($normal->plainTextToken)->getJson("/txapi/admin/{$path}/modules")
            ->assertOk()->assertJsonMissing(['id' => 'agent_ops']);
        $expired = $admin->createToken('expired-admin', ['*'], now()->subMinute());
        Auth::forgetGuards();
        $this->withToken($expired->plainTextToken)->getJson('/txapi/me')->assertUnauthorized();
    }

    private function admin(): User
    {
        return User::create([
            'email' => 'retirement@example.test', 'password' => 'fixture',
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'token' => bin2hex(random_bytes(16)),
            'is_admin' => 1, 'is_staff' => 0, 'banned' => 0,
            'balance' => 0, 'commission_balance' => 0, 'transfer_enable' => 0, 'u' => 0, 'd' => 0,
        ]);
    }
}
