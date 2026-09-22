<?php

namespace Tests\Feature\Agent;

use App\Models\AgentAction;
use App\Models\AgentInspection;
use App\Models\Server;
use App\Models\User;
use App\Services\AgentOps\AgentAbility;
use App\Services\AgentOps\AgentInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AgentInsightTest extends TestCase
{
    use RefreshDatabase;

    public function test_fleet_health_marks_an_offline_control_channel_critical(): void
    {
        $node = $this->makeNode('offline-node');
        $fleet = app(AgentInsightService::class)->fleetHealth();

        $this->assertSame('critical', $fleet['status']);
        $this->assertSame(1, $fleet['summary']['total_nodes']);
        $this->assertSame(1, $fleet['summary']['critical_nodes']);
        $this->assertSame($node->id, $fleet['nodes'][0]['node_id']);
        $this->assertContains(
            'websocket_offline',
            collect($fleet['nodes'][0]['warnings'])->pluck('code')->all()
        );
    }

    public function test_inspection_command_persists_a_normalized_snapshot(): void
    {
        $this->makeNode('scheduled-node');

        $exit = Artisan::call('agent:inspect-fleet', ['--source' => 'test']);

        $this->assertSame(0, $exit);
        $this->assertDatabaseCount('v2_agent_inspection', 1);

        $inspection = AgentInspection::query()->firstOrFail();
        $this->assertSame('test', $inspection->source);
        $this->assertSame('critical', $inspection->status);
        $this->assertCount(1, $inspection->findings);
    }

    public function test_remediation_plan_never_auto_executes(): void
    {
        $node = $this->makeNode('plan-node');

        $plan = app(AgentInsightService::class)->remediationPlan($node->id);

        $this->assertFalse($plan['automatic_remediation_enabled']);
        $this->assertSame('recommend_then_approve_then_execute_then_verify', $plan['policy']);
        $this->assertContains(
            'websocket_offline',
            collect($plan['recommendations'])->pluck('reason_code')->all()
        );
        $this->assertFalse((bool) $plan['recommendations'][0]['automatic_execution']);
    }

    public function test_incident_timeline_composes_inspection_and_action_events(): void
    {
        $admin = $this->makeAdmin();
        $node = $this->makeNode('timeline-node');

        app(AgentInsightService::class)->runInspection('test');

        AgentAction::create([
            'request_id' => 'ops_timeline_test',
            'admin_id' => $admin->id,
            'node_id' => $node->id,
            'action' => 'ops.kernel.restart',
            'risk_level' => 'operate',
            'status' => AgentAction::STATUS_FAILED,
            'input' => [],
            'error_code' => 'kernel_restart_failed',
            'finished_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $timeline = app(AgentInsightService::class)->incidentTimeline($node->id);

        $types = collect($timeline['events'])->pluck('type')->all();
        $this->assertContains('inspection_state_changed', $types);
        $this->assertContains('action_requested', $types);
        $this->assertContains('action_finished', $types);
    }

    public function test_verify_action_does_not_trust_success_ack_when_node_is_still_offline(): void
    {
        $admin = $this->makeAdmin();
        $node = $this->makeNode('verify-node');

        AgentAction::create([
            'request_id' => 'ops_verify_test',
            'admin_id' => $admin->id,
            'node_id' => $node->id,
            'action' => 'ops.kernel.restart',
            'risk_level' => 'operate',
            'status' => AgentAction::STATUS_SUCCEEDED,
            'input' => [],
            'result' => ['kernel_running' => true],
            'started_at' => time() - 2,
            'finished_at' => time() - 1,
            'created_at' => time() - 3,
            'updated_at' => time() - 1,
        ]);

        $verification = app(AgentInsightService::class)->verifyAction('ops_verify_test');

        $this->assertSame('succeeded', $verification['action_status']);
        $this->assertSame('failed', $verification['verification_status']);
        $this->assertFalse(
            collect($verification['checks'])
                ->firstWhere('name', 'websocket_connected')['passed']
        );
    }

    public function test_verify_action_reports_deleted_target_as_inconclusive(): void
    {
        $admin = $this->makeAdmin();
        $node = $this->makeNode('deleted-target');

        AgentAction::create([
            'request_id' => 'ops_deleted_target',
            'admin_id' => $admin->id,
            'node_id' => $node->id,
            'action' => 'ops.kernel.restart',
            'risk_level' => 'operate',
            'status' => AgentAction::STATUS_SUCCEEDED,
            'input' => [],
            'result' => ['kernel_running' => true],
            'started_at' => time() - 2,
            'finished_at' => time() - 1,
            'created_at' => time() - 3,
            'updated_at' => time() - 1,
        ]);

        $node->delete();

        $verification = app(AgentInsightService::class)->verifyAction('ops_deleted_target');

        $this->assertSame('inconclusive', $verification['verification_status']);
        $this->assertSame('target_missing', $verification['error_code']);
    }

    public function test_insight_endpoints_respect_target_scope(): void
    {
        $admin = $this->makeAdmin();
        $allowed = $this->makeNode('allowed-insight');
        $this->makeNode('blocked-insight');

        $issued = $admin->createToken('agent:insight-scope', [
            AgentAbility::INSIGHTS_READ,
            'agent:target:restricted',
            'agent:target:node:' . $allowed->id,
        ]);

        $response = $this->withToken($issued->plainTextToken)
            ->getJson('/api/v2/agent/fleet/health');

        $response->assertOk()
            ->assertJsonPath('data.summary.total_nodes', 1)
            ->assertJsonPath('data.nodes.0.node_id', $allowed->id);
    }

    public function test_agent_can_read_inspection_history_and_remediation_plan(): void
    {
        $admin = $this->makeAdmin();
        $node = $this->makeNode('api-insight');
        app(AgentInsightService::class)->runInspection('test');

        $issued = $admin->createToken('agent:insights', [
            AgentAbility::INSIGHTS_READ,
        ]);

        $this->withToken($issued->plainTextToken)
            ->getJson('/api/v2/agent/inspections?limit=5')
            ->assertOk()
            ->assertJsonPath('data.0.source', 'test');

        $this->withToken($issued->plainTextToken)
            ->getJson("/api/v2/agent/nodes/{$node->id}/remediation")
            ->assertOk()
            ->assertJsonPath('data.automatic_remediation_enabled', false);
    }

    private function makeNode(string $name): Server
    {
        return Server::create([
            'type' => 'vless',
            'name' => $name,
            'rate' => 1,
            'host' => $name . '.example.com',
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
            'email' => "insight-admin-{$sequence}@example.com",
            'password' => 'password',
            'uuid' => sprintf('10000000-0000-0000-0000-%012d', 900 + $sequence),
            'token' => str_pad((string) (900 + $sequence), 32, 'b', STR_PAD_LEFT),
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
