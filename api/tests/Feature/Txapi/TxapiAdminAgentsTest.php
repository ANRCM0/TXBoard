<?php

namespace Tests\Feature\Txapi;

use App\Models\User;
use App\Services\AgentOps\AgentAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TxapiAdminAgentsTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/txapi/admin/native_agents_secret/agents';

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['secure_path' => 'native_agents_secret']);
    }

    public function test_admin_token_and_approval_routes_require_admin_role_and_secret_path(): void
    {
        $this->getJson(self::ROOT . '/abilities')->assertStatus(403);
        $this->getJson(self::ROOT . '/tokens')->assertStatus(403);
        $this->postJson(self::ROOT . '/tokens', ['client_name' => 'unauthorized'])->assertStatus(403);
        $this->postJson(self::ROOT . '/actions/approve', ['request_id' => 'ops_invalid'])->assertStatus(403);

        Sanctum::actingAs($this->user('agent-ordinary@example.test'));
        $this->getJson(self::ROOT . '/tokens')->assertStatus(403);
        $this->getJson(self::ROOT . '/support/reply-requests')->assertStatus(403);
        $this->postJson(self::ROOT . '/support/reply-requests/reject', ['request_id' => 'r_1'])->assertStatus(403);

        Sanctum::actingAs($this->user('agent-root@example.test', true));
        $this->getJson('/txapi/admin/incorrect/agents/tokens')->assertStatus(404);
        $this->getJson(self::ROOT . '/abilities')->assertOk()
            ->assertJsonStructure(['data' => ['default_read', 'all'], 'request_id']);
        $this->getJson(self::ROOT . '/actions?status=invalid')->assertStatus(422);
        $this->getJson(self::ROOT . '/inspections?limit=51')->assertStatus(422);
        $this->getJson(self::ROOT . '/support/reply-requests?limit=51')->assertStatus(422);
    }

    public function test_token_inventory_is_owned_scoped_bounded_and_never_discloses_secrets(): void
    {
        $adminA = $this->user('agent-ownerA@example.test', true);
        $adminB = $this->user('agent-ownerB@example.test', true);
        $other = $adminB->createToken('agent:other-owner', [AgentAbility::NODES_READ]);

        Sanctum::actingAs($adminA);
        $created = $this->postJson(self::ROOT . '/tokens', [
            'client_name' => 'test-agent',
            'abilities' => [AgentAbility::NODES_READ],
            'expires_in_days' => 7,
        ])->assertStatus(201);
        $id = (int) $created->json('data.id');
        $plain = $created->json('data.plain_text_token');
        $this->assertNotEmpty($plain);
        $this->assertStringContainsString('no-store', (string) $created->headers->get('Cache-Control'));

        $list = $this->getJson(self::ROOT . '/tokens?per_page=1&page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $id);
        $this->assertStringNotContainsString($plain, $list->getContent());
        $this->assertStringNotContainsString($other->plainTextToken, $list->getContent());
        $this->getJson(self::ROOT . '/tokens?per_page=101')->assertStatus(422);

        $this->deleteJson(self::ROOT . '/tokens/' . $other->accessToken->id)
            ->assertStatus(404)->assertJsonPath('error.code', 'AGENT_TOKEN_NOT_FOUND');
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->deleteJson(self::ROOT . '/tokens/' . $id)
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $id]);
    }

    public function test_restricted_scopes_cannot_include_global_support_abilities(): void
    {
        Sanctum::actingAs($this->user('agent-scope@example.test', true));
        $this->postJson(self::ROOT . '/tokens', [
            'client_name' => 'bad-scope',
            'abilities' => [AgentAbility::SUPPORT_READ],
            'target_mode' => 'restricted',
            'target_node_ids' => [999999],
        ])->assertStatus(422);
        $this->postJson(self::ROOT . '/tokens', [
            'client_name' => 'bad-scope',
            'abilities' => [AgentAbility::SUPPORT_READ],
            'target_mode' => 'restricted',
        ])->assertStatus(422)->assertJsonValidationErrors('target_scope');
    }

    public function test_invalid_approvals_are_bounded_and_legacy_v2_admin_agent_paths_are_gone(): void
    {
        Sanctum::actingAs($this->user('agent-approval@example.test', true));
        $this->postJson(self::ROOT . '/actions/approve', ['request_id' => 'not_found'])
            ->assertStatus(404);
        $this->postJson(self::ROOT . '/actions/reject', ['request_id' => '../bad'])
            ->assertStatus(422);
        $this->postJson(self::ROOT . '/support/reply-requests/approve',
            ['request_id' => 'bad/reply'])->assertStatus(422);

        $uris = collect(Route::getRoutes()->getRoutes())->map(static fn ($route) => $route->uri())->all();
        foreach (['agent/abilities', 'agent/tokens', 'agent/tokens/create',
            'agent/tokens/revoke', 'agent/actions', 'agent/actions/approve',
            'agent/actions/reject', 'agent/support/reply-requests',
            'agent/support/reply-requests/approve', 'agent/support/reply-requests/reject',
            'agent/fleet/health', 'agent/inspections'] as $old) {
            $this->assertNotContains('api/v2/{admin_path}/' . $old, $uris);
        }
    }

    private function user(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email, 'password' => 'test-password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'is_admin' => $admin,
            'banned' => 0,
        ]);
    }
}
