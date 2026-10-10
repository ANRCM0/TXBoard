<?php

namespace Tests\Feature\Server;

use App\Jobs\TrafficBatchJob;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TxNodeNativeProtocolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting([
            'server_token' => 'native-test-global-token',
            'server_push_interval' => 60,
            'server_pull_interval' => 75,
        ]);
    }

    private function node(array $overrides = []): Server
    {
        return Server::create(array_merge([
            'name' => 'native-test-node', 'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443,
            'rate' => 1, 'group_ids' => [1], 'enabled' => true,
        ], $overrides));
    }

    private function headers(Server $node, string $token = 'native-test-global-token'): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'X-TX-Node-ID' => (string) $node->id,
        ];
    }

    public function test_header_auth_responds_with_native_versioned_handshake_and_no_token_echo(): void
    {
        $node = $this->node();
        $response = $this->postJson('/txapi/node/v1/handshake', [], $this->headers($node));
        $response->assertOk()
            ->assertJsonPath('data.protocol_version', 1)
            ->assertJsonPath('data.node_id', $node->id)
            ->assertJsonPath('data.websocket.enabled', false)
            ->assertJsonPath('data.settings.push_interval', 60);
        $this->assertContains('traffic_batch_v1', $response->json('data.capabilities'));
        $this->assertSame($response->json('request_id'),
            $response->headers->get('X-Request-Id'));
        $this->assertStringNotContainsString('native-test-global-token', $response->getContent());
    }

    public function test_query_or_body_tokens_are_never_accepted_and_unknown_nodes_fail(): void
    {
        $node = $this->node();
        $this->postJson('/txapi/node/v1/handshake', [
            'token' => 'native-test-global-token',
            'node_id' => $node->id,
        ])->assertStatus(401)->assertJsonPath('error.code', 'NODE_UNAUTHORIZED');
        $this->getJson('/txapi/node/v1/config?token=native-test-global-token&node_id='.$node->id)
            ->assertStatus(401);
        $this->postJson('/txapi/node/v1/handshake', [],
            $this->headers($node, 'wrong'))->assertStatus(401);
        $this->postJson('/txapi/node/v1/handshake', [],
            ['Authorization' => 'Bearer native-test-global-token', 'X-TX-Node-ID' => '999999'])
            ->assertStatus(404)->assertJsonPath('error.code', 'NODE_NOT_FOUND');
    }

    public function test_machine_credentials_cannot_read_another_machine_nodes(): void
    {
        $machine = ServerMachine::create([
            'name' => 'native-machine', 'token' => 'machine-native-credential',
            'is_active' => true,
        ]);
        $other = ServerMachine::create([
            'name' => 'other-machine', 'token' => 'other-credential',
            'is_active' => true,
        ]);
        $node = $this->node(['machine_id' => $machine->id]);
        $this->node(['machine_id' => $other->id, 'name' => 'foreign-node']);
        $headers = ['Authorization' => 'Bearer machine-native-credential',
            'X-TX-Machine-ID' => (string) $machine->id,
            'X-TX-Node-ID' => (string) $node->id];
        $this->getJson('/txapi/node/v1/machine/nodes', $headers)->assertOk()
            ->assertJsonCount(1, 'data.nodes')
            ->assertJsonPath('data.nodes.0.id', $node->id);
        $this->postJson('/txapi/node/v1/handshake', [], $headers)->assertOk()
            ->assertJsonPath('data.mode', 'node');
        $this->postJson('/txapi/node/v1/handshake', [], [
            'Authorization' => 'Bearer machine-native-credential',
            'X-TX-Machine-ID' => (string) $machine->id,
        ])->assertOk()->assertJsonPath('data.mode', 'machine')
            ->assertJsonPath('data.node_id', null);

        $foreign = $this->node(['machine_id' => $other->id, 'name' => 'denied-node']);
        $headers['X-TX-Node-ID'] = (string) $foreign->id;
        $this->postJson('/txapi/node/v1/handshake', [], $headers)
            ->assertStatus(404)->assertJsonPath('error.code', 'NODE_NOT_FOUND');
        $this->getJson('/txapi/node/v1/machine/nodes', [
            'Authorization' => 'Bearer native-test-global-token',
        ])->assertStatus(401);
        $machine->update(['is_active' => false]);
        $this->getJson('/txapi/node/v1/machine/nodes', $headers)->assertStatus(401);
    }

    public function test_machine_status_is_scoped_and_has_strict_bounds(): void
    {
        $machine = ServerMachine::create([
            'name' => 'status-machine', 'token' => 'status-machine-token',
            'is_active' => true,
        ]);
        $headers = ['Authorization' => 'Bearer status-machine-token',
            'X-TX-Machine-ID' => (string) $machine->id];
        $this->postJson('/txapi/node/v1/machine/status', [
            'protocol_version' => 1, 'cpu' => 25,
            'mem' => ['total' => 1024, 'used' => 512],
            'net' => ['in_speed' => 8, 'out_speed' => 12],
            'runtime' => [
                'version' => 'v2.4.0',
                'deployment' => 'docker',
                'updater_available' => true,
                'update' => [
                    'request_id' => 'mup_secure',
                    'target' => 'latest',
                    'status' => 'running',
                    'updated_at' => 1780000001,
                    'message' => 'leaked token=secret',
                ],
            ],
        ], $headers)->assertOk()->assertJsonPath('data.accepted', true);
        $this->assertSame(512, $machine->fresh()->load_status['mem']['used']);
        $this->assertSame(8, $machine->fresh()->load_status['net']['in_speed']);
        $this->assertSame('v2.4.0', $machine->fresh()->load_status['runtime']['version']);
        $this->assertSame('[REDACTED]', $machine->fresh()->load_status['runtime']['update']['message']);
        $this->assertTrue(\App\Models\ServerMachineLoadHistory::query()
            ->where('machine_id', $machine->id)->exists());
        $this->postJson('/txapi/node/v1/machine/status', [
            'protocol_version' => 1, 'cpu' => 101,
            'mem' => ['total' => 1024, 'used' => 2048],
        ], $headers)->assertStatus(422);
        $this->postJson('/txapi/node/v1/machine/status', [
            'protocol_version' => 1, 'cpu' => 25,
            'mem' => ['total' => 1024, 'used' => 512],
        ], ['Authorization' => 'Bearer native-test-global-token'])
            ->assertStatus(401);
    }

    public function test_etags_scope_configuration_and_user_snapshot_to_node(): void
    {
        $node = $this->node();
        $headers = $this->headers($node);
        $response = $this->getJson('/txapi/node/v1/config', $headers);
        $response->assertOk()->assertJsonPath('data.protocol_version', 1)
            ->assertJsonPath('data.config.protocol', Server::TYPE_VMESS);
        $tag = $response->headers->get('ETag');
        $this->assertNotEmpty($tag);
        $this->getJson('/txapi/node/v1/config', $headers + ['If-None-Match' => $tag])
            ->assertStatus(304);
        $this->getJson('/txapi/node/v1/users', $headers)
            ->assertOk()->assertJsonPath('data.users', []);
    }

    public function test_report_requires_stable_batch_and_acknowledges_only_enqueue(): void
    {
        Bus::fake();
        $node = $this->node();
        $headers = $this->headers($node);
        $this->postJson('/txapi/node/v1/report', [
            'protocol_version' => 1, 'traffic' => ['1' => [100, 200]],
        ], $headers)->assertStatus(422)->assertJsonPath('error.code', 'BATCH_ID_REQUIRED');

        $this->postJson('/txapi/node/v1/report', [
            'protocol_version' => 2, 'traffic_batch_id' => 'native-batch-001',
            'traffic' => ['1' => [100, 200]],
        ], $headers)->assertStatus(422);

        $this->postJson('/txapi/node/v1/report', [
            'protocol_version' => 1, 'traffic_batch_id' => 'native-batch-001',
            'traffic' => ['1' => [-1, 200]],
        ], $headers)->assertStatus(422)->assertJsonPath('error.code', 'INVALID_TRAFFIC');

        $response = $this->postJson('/txapi/node/v1/report', [
            'protocol_version' => 1, 'traffic_batch_id' => 'native-batch-001',
            'traffic' => ['1' => [100, 200]],
        ], $headers);
        $response->assertStatus(202)->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.settlement', 'queued')
            ->assertJsonPath('data.traffic_batch_id', 'native-batch-001');
        Bus::assertDispatched(TrafficBatchJob::class, 1);

        // Reception is not a completed financial settlement.
        $this->assertDatabaseCount('v2_traffic_batch', 0);
    }

    public function test_obsolete_node_paths_are_not_registered(): void
    {
        $registered = array_map(static fn ($route) => $route->uri(),
            \Illuminate\Support\Facades\Route::getRoutes()->getRoutes());
        $this->assertNotContains('api/v1/server/UniProxy/config', $registered);
        $this->assertNotContains('api/v2/server/report', $registered);
        $this->assertContains('txapi/node/v1/report', $registered);
    }

}
