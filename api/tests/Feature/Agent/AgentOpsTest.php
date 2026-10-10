<?php

namespace Tests\Feature\Agent;

use App\Models\AgentAction;
use App\Models\AgentAuditLog;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentOps\AgentAbility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentOpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_route_requires_an_agent_named_token(): void
    {
        $admin = $this->makeAdmin();
        $plain = $admin->createToken('ordinary-api-token', [AgentAbility::NODES_READ])->plainTextToken;

        $this->withToken($plain)
            ->getJson('/txapi/agent/v1/whoami')
            ->assertForbidden();
    }

    public function test_wildcard_sanctum_token_named_agent_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $plain = $admin->createToken('agent:renamed-root', ['*'])->plainTextToken;

        $this->withToken($plain)->getJson('/txapi/agent/v1/whoami')
            ->assertForbidden()->assertJsonPath('message', 'Invalid Agent scopes');
        $this->withToken($plain)->getJson('/txapi/agent/v1/system/status')
            ->assertForbidden();
    }

    public function test_agent_with_only_read_ability_cannot_execute_node_actions(): void
    {
        $admin = $this->makeAdmin();
        $plain = $admin->createToken('agent:read-only', [AgentAbility::NODES_READ])->plainTextToken;

        $this->withToken($plain)->postJson('/txapi/agent/v1/nodes/1/actions', [
            'action' => 'ops.kernel.restart',
        ])->assertForbidden();
    }

    public function test_agent_token_can_identify_itself_without_exposing_the_secret(): void
    {
        $admin = $this->makeAdmin();
        $plain = $admin->createToken('agent:test-client', [
            AgentAbility::NODES_READ,
            AgentAbility::NODES_OPERATE,
        ])->plainTextToken;

        $response = $this->withToken($plain)->getJson('/txapi/agent/v1/whoami');

        $response->assertOk()
            ->assertJsonPath('data.admin_id', $admin->id)
            ->assertJsonPath('data.client_name', 'test-client');

        $this->assertContains(AgentAbility::NODES_READ, $response->json('data.abilities'));
        $this->assertStringNotContainsString($plain, (string) $response->getContent());
    }

    public function test_agent_abilities_are_enforced_per_endpoint(): void
    {
        $admin = $this->makeAdmin();
        $plain = $admin->createToken('agent:least-privilege', [
            AgentAbility::NODES_READ,
        ])->plainTextToken;

        $this->withToken($plain)
            ->getJson('/txapi/agent/v1/traffic/summary')
            ->assertForbidden();
    }

    public function test_operating_tool_only_creates_a_pending_action(): void
    {
        $admin = $this->makeAdmin();
        $node = Server::create([
            'type' => 'vless',
            'name' => 'agent-test-node',
            'rate' => 1,
            'host' => 'node.example.com',
            'port' => '443',
            'server_port' => 443,
            'group_ids' => [],
            'route_ids' => [],
            'protocol_settings' => [],
            'show' => true,
        ]);

        $plain = $admin->createToken('agent:operator', [
            AgentAbility::NODES_READ,
            AgentAbility::NODES_OPERATE,
        ])->plainTextToken;

        $response = $this->withToken($plain)->postJson("/txapi/agent/v1/nodes/{$node->id}/actions", [
            'action' => 'ops.kernel.restart',
            'input' => [],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', AgentAction::STATUS_PENDING)
            ->assertJsonPath('data.node_id', $node->id)
            ->assertJsonPath('data.action', 'ops.kernel.restart');

        $action = AgentAction::query()->firstOrFail();
        $this->assertSame(AgentAction::STATUS_PENDING, $action->status);
        $this->assertNull($action->approved_at);
        $this->assertNull($action->started_at);
        $this->assertNull($action->finished_at);
    }

    public function test_admin_can_issue_and_revoke_a_scoped_agent_token(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $securePath = (string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );

        $created = $this->postJson("/txapi/admin/{$securePath}/agents/tokens", [
            'client_name' => 'mcp-test',
            'abilities' => [AgentAbility::NODES_READ, AgentAbility::NODES_DIAGNOSE],
            'expires_in_days' => 7,
        ]);

        $created->assertStatus(201);
        $plain = (string) $created->json('data.plain_text_token');
        $this->assertNotSame('', $plain);

        $id = (int) $created->json('data.id');
        $list = $this->getJson("/txapi/admin/{$securePath}/agents/tokens");
        $list->assertOk();
        $this->assertSame('mcp-test', $list->json('data.0.client_name'));
        $this->assertStringNotContainsString($plain, (string) $list->getContent());

        $this->deleteJson("/txapi/admin/{$securePath}/agents/tokens/{$id}")
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $id]);
    }


    public function test_restricted_agent_token_filters_nodes_and_blocks_other_nodes(): void
    {
        $admin = $this->makeAdmin();
        $allowed = $this->makeNode('scope-allowed', 'allowed.example.com');
        $blocked = $this->makeNode('scope-blocked', 'blocked.example.com');

        $issued = $admin->createToken('agent:scoped', [
            AgentAbility::NODES_READ,
            AgentAbility::METRICS_READ,
            'agent:target:restricted',
            'agent:target:node:' . $allowed->id,
        ]);

        $list = $this->withToken($issued->plainTextToken)->getJson('/txapi/agent/v1/nodes');
        $list->assertOk();
        $this->assertSame([$allowed->id], collect($list->json('data'))->pluck('id')->all());

        $this->withToken($issued->plainTextToken)
            ->getJson("/txapi/agent/v1/nodes/{$blocked->id}/metrics")
            ->assertForbidden();
    }

    public function test_recent_completed_action_enforces_cooldown(): void
    {
        config()->set('agent_ops.action_cooldown', 60);

        $admin = $this->makeAdmin();
        $node = $this->makeNode('cooldown-node', 'cooldown.example.com');
        $issued = $admin->createToken('agent:cooldown', [
            AgentAbility::NODES_READ,
            AgentAbility::NODES_OPERATE,
        ]);

        AgentAction::create([
            'request_id' => 'ops_existing_cooldown',
            'admin_id' => $admin->id,
            'token_id' => $issued->accessToken->id,
            'node_id' => $node->id,
            'action' => 'ops.kernel.restart',
            'risk_level' => 'operate',
            'status' => AgentAction::STATUS_SUCCEEDED,
            'input' => [],
            'finished_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->withToken($issued->plainTextToken)
            ->postJson("/txapi/agent/v1/nodes/{$node->id}/actions", [
                'action' => 'ops.kernel.restart',
                'input' => [],
            ])
            ->assertStatus(422);
    }

    public function test_log_tail_request_is_bounded_and_stays_pending(): void
    {
        $admin = $this->makeAdmin();
        $node = $this->makeNode('logs-node', 'logs.example.com');
        $issued = $admin->createToken('agent:logs', [
            AgentAbility::NODES_READ,
            AgentAbility::NODES_DIAGNOSE,
        ]);

        $this->withToken($issued->plainTextToken)
            ->postJson("/txapi/agent/v1/nodes/{$node->id}/actions", [
                'action' => 'ops.logs.tail',
                'input' => ['source' => 'application', 'lines' => 201],
            ])
            ->assertStatus(422);

        $ok = $this->withToken($issued->plainTextToken)
            ->postJson("/txapi/agent/v1/nodes/{$node->id}/actions", [
                'action' => 'ops.logs.tail',
                'input' => ['source' => 'application', 'lines' => 50],
            ]);

        $ok->assertOk()
            ->assertJsonPath('data.status', AgentAction::STATUS_PENDING)
            ->assertJsonPath('data.input.source', 'application')
            ->assertJsonPath('data.input.lines', 50);
    }

    public function test_admin_can_issue_a_restricted_target_token(): void
    {
        $admin = $this->makeAdmin();
        $node = $this->makeNode('admin-scope-node', 'admin-scope.example.com');
        Sanctum::actingAs($admin);

        $securePath = (string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );

        $response = $this->postJson("/txapi/admin/{$securePath}/agents/tokens", [
            'client_name' => 'restricted-client',
            'abilities' => [AgentAbility::NODES_READ],
            'expires_in_days' => 7,
            'target_mode' => 'restricted',
            'target_node_ids' => [$node->id],
            'target_machine_ids' => [],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.target_scope.mode', 'restricted')
            ->assertJsonPath('data.target_scope.node_ids.0', $node->id);
    }


    public function test_agent_audit_records_protocol_and_timing_context(): void
    {
        $admin = $this->makeAdmin();
        $issued = $admin->createToken('agent:audit-context', [
            AgentAbility::NODES_READ,
        ]);

        $this->withHeader('X-Agent-Protocol', 'mcp')
            ->withToken($issued->plainTextToken)
            ->getJson('/txapi/agent/v1/whoami')
            ->assertOk();

        $log = AgentAuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame('agent', $log->actor_type);
        $this->assertSame('mcp', $log->protocol);
        $this->assertNotNull($log->started_at);
        $this->assertNotNull($log->finished_at);
        $this->assertFalse((bool) $log->approval_required);
    }

    private function makeNode(string $name, string $host): Server
    {
        return Server::create([
            'type' => 'vless',
            'name' => $name,
            'rate' => 1,
            'host' => $host,
            'port' => '443',
            'server_port' => 443,
            'group_ids' => [],
            'route_ids' => [],
            'protocol_settings' => [],
            'show' => true,
        ]);
    }

    private function makeAdmin(): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'email' => "agent-admin-{$sequence}@example.com",
            'password' => 'password',
            'uuid' => sprintf('00000000-0000-0000-0000-%012d', 800 + $sequence),
            'token' => str_pad((string) (800 + $sequence), 32, 'a', STR_PAD_LEFT),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 1,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
