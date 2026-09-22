<?php

namespace Tests\Feature\Agent;

use App\Models\AgentAction;
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
            ->getJson('/api/v2/agent/whoami')
            ->assertForbidden();
    }

    public function test_agent_token_can_identify_itself_without_exposing_the_secret(): void
    {
        $admin = $this->makeAdmin();
        $plain = $admin->createToken('agent:test-client', [
            AgentAbility::NODES_READ,
            AgentAbility::NODES_OPERATE,
        ])->plainTextToken;

        $response = $this->withToken($plain)->getJson('/api/v2/agent/whoami');

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
            ->getJson('/api/v2/agent/traffic/summary')
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

        $response = $this->withToken($plain)->postJson("/api/v2/agent/nodes/{$node->id}/actions", [
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

        $created = $this->postJson("/api/v2/{$securePath}/agent/tokens/create", [
            'client_name' => 'mcp-test',
            'abilities' => [AgentAbility::NODES_READ, AgentAbility::NODES_DIAGNOSE],
            'expires_in_days' => 7,
        ]);

        $created->assertOk();
        $plain = (string) $created->json('data.plain_text_token');
        $this->assertNotSame('', $plain);

        $id = (int) $created->json('data.id');
        $list = $this->getJson("/api/v2/{$securePath}/agent/tokens");
        $list->assertOk();
        $this->assertSame('mcp-test', $list->json('data.0.client_name'));
        $this->assertStringNotContainsString($plain, (string) $list->getContent());

        $this->postJson("/api/v2/{$securePath}/agent/tokens/revoke", ['id' => $id])
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $id]);
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
