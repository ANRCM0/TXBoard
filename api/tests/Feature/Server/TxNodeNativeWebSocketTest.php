<?php

namespace Tests\Feature\Server;

use App\Jobs\TrafficBatchJob;
use App\Models\AgentAction;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\NodeRegistry;
use App\WebSocket\NativeNodeFrame;
use App\WebSocket\NativeNodeWebSocket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request as WsRequest;

class TxNodeNativeWebSocketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['server_token' => 'native-ws-test-token']);
        config()->set('node_ws.native_enabled', true);
    }

    private function node(array $attributes = []): Server
    {
        return Server::create(array_merge([
            'name' => 'native-ws', 'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443,
            'group_ids' => [1], 'rate' => 1, 'enabled' => true,
        ], $attributes));
    }

    private function connection(array &$sent): TcpConnection
    {
        $conn = Mockery::mock(TcpConnection::class);
        $conn->shouldReceive('send')->andReturnUsing(function ($payload) use (&$sent) {
            $sent[] = json_decode((string) $payload, true, 32, JSON_THROW_ON_ERROR);
            return true;
        });
        $conn->shouldReceive('close')->zeroOrMoreTimes();
        return $conn;
    }

    private function upgrade(?int $nodeId, string $token, ?int $machineId = null): WsRequest
    {
        $headers = "GET /txapi/node/v1/ws HTTP/1.1\r\nHost: board.example.test\r\n"
            ."Upgrade: websocket\r\nConnection: Upgrade\r\n"
            ."Authorization: Bearer {$token}\r\n";
        if ($nodeId !== null) {
            $headers .= "X-TX-Node-ID: {$nodeId}\r\n";
        }
        if ($machineId !== null) {
            $headers .= "X-TX-Machine-ID: {$machineId}\r\n";
        }
        return new WsRequest($headers."\r\n");
    }

    public function test_worker_rejects_old_upgrade_and_unversioned_frames(): void
    {
        $worker = new \App\WebSocket\NodeWorker('127.0.0.1', 8077);
        $old = Mockery::mock(TcpConnection::class);
        $old->shouldReceive('close')->once()->with(Mockery::on(
            static fn ($frame): bool =>
                (json_decode((string) $frame, true)['data']['code'] ?? null) === 'UNKNOWN_WS_PATH'
        ));
        $worker->onWebSocketConnect($old, new WsRequest(
            "GET /ws?token=old-credential HTTP/1.1\r\nHost: local\r\n\r\n"
        ));

        $notAuthenticated = Mockery::mock(TcpConnection::class);
        $notAuthenticated->shouldReceive('close')->once()->with(Mockery::on(
            static fn ($frame): bool =>
                (json_decode((string) $frame, true)['data']['code'] ?? null) === 'UNAUTHORIZED'
        ));
        $worker->onMessage($notAuthenticated, '{"event":"pong"}');
    }

    public function test_handshake_advertises_ws_only_when_configured(): void
    {
        $node = $this->node();
        $headers = ['Authorization' => 'Bearer native-ws-test-token',
            'X-TX-Node-ID' => (string) $node->id];
        config()->set('node_ws.native_enabled', false);
        $this->postJson('/txapi/node/v1/handshake', [], $headers)
            ->assertOk()->assertJsonPath('data.websocket.enabled', false);
        config()->set('node_ws.native_enabled', true);
        $this->postJson('/txapi/node/v1/handshake', [], $headers)
            ->assertOk()->assertJsonPath('data.websocket.enabled', true)
            ->assertJsonPath('data.websocket.path', '/txapi/node/v1/ws');
    }

    public function test_native_frame_has_bounded_versioned_envelope(): void
    {
        $frame = NativeNodeFrame::encode('traffic.ack', ['accepted' => true], 'req-1');
        $this->assertSame('req-1', NativeNodeFrame::parse($frame)['request_id']);
        $this->assertSame(1, NativeNodeFrame::parse($frame)['protocol_version']);
        foreach ([
            '{"protocol_version":2,"event":"heartbeat.ping","data":{},"request_id":"r"}',
            '{"protocol_version":1,"event":"x","data":[1],"request_id":"r"}',
            '{"protocol_version":1,"event":"x","data":{},"request_id":"oops with spaces"}',
            str_repeat('x', NativeNodeFrame::MAX_BYTES + 1),
        ] as $raw) {
            try {
                NativeNodeFrame::parse($raw);
                $this->fail('Invalid native WS frames must be rejected');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_native_socket_authorizes_header_only_and_pushes_initial_snapshots(): void
    {
        $node = $this->node();
        $sent = [];
        $conn = $this->connection($sent);
        $ws = app(NativeNodeWebSocket::class);
        try {
            $ws->connect($conn, $this->upgrade($node->id, 'native-ws-test-token'));
            $this->assertTrue($conn->txnodeNative);
            $this->assertSame('session.ready', $sent[0]['event']);
            $this->assertSame(1, $sent[0]['protocol_version']);
            $this->assertSame('node', $sent[0]['data']['mode']);
            $this->assertContains('sync.config', array_column($sent, 'event'));
            $this->assertContains('sync.users', array_column($sent, 'event'));
            $this->assertStringNotContainsString('native-ws-test-token', json_encode($sent));

            $ws->message($conn, NativeNodeFrame::encode('heartbeat.ping', [], 'heart-1'));
            $this->assertSame('heartbeat.ack', end($sent)['event']);
            $this->assertSame('heart-1', end($sent)['request_id']);

            $node->update(['enabled' => false]);
            $ws->message($conn, NativeNodeFrame::encode('sync.request', [], 'denied'));
            $this->assertSame('NODE_NOT_FOUND', end($sent)['data']['code']);
        } finally {
            NodeRegistry::remove((int) $node->id, $conn);
        }
    }

    public function test_machine_scope_rejects_other_machine_traffic_and_token_revocation(): void
    {
        Bus::fake();
        $machine = ServerMachine::create([
            'name' => 'first', 'token' => 'machine-ws-key', 'is_active' => true,
        ]);
        $other = ServerMachine::create([
            'name' => 'second', 'token' => 'second-key', 'is_active' => true,
        ]);
        $node = $this->node(['machine_id' => $machine->id]);
        $foreign = $this->node(['machine_id' => $other->id, 'name' => 'foreign']);
        $sent = [];
        $conn = $this->connection($sent);
        $ws = app(NativeNodeWebSocket::class);
        try {
            $ws->connect($conn, $this->upgrade(null, 'machine-ws-key', $machine->id));
            $this->assertSame('machine', $sent[0]['data']['mode']);
            $this->assertContains('sync.nodes', array_column($sent, 'event'));
            $ws->message($conn, NativeNodeFrame::encode('traffic.report', [
                'node_id' => $foreign->id, 'protocol_version' => 1,
                'traffic_batch_id' => 'foreign-batch-001',
                'traffic' => ['1' => [100, 100]],
            ], 'foreign'));
            $this->assertSame('NODE_NOT_FOUND', end($sent)['data']['code']);
            Bus::assertNotDispatched(TrafficBatchJob::class);

            // A healthy machine connection must not be compared against
            // the global single-node server token during reconciliation.
            $worker = new \App\WebSocket\NodeWorker('127.0.0.1', 8077);
            (new \ReflectionMethod($worker, 'reconcileConnections'))->invoke($worker);
            $this->assertSame($conn, NodeRegistry::getMachine((int) $machine->id));

            $machine->update(['token' => 'newly-rotated']);
            $ws->message($conn, NativeNodeFrame::encode('heartbeat.ping', [], 'revoked'));
            $this->assertSame('UNAUTHORIZED', end($sent)['data']['code']);
        } finally {
            NodeRegistry::removeMachine((int) $machine->id, $conn);
            NodeRegistry::remove((int) $node->id, $conn);
        }
    }

    public function test_native_operation_result_is_scoped_validated_and_idempotent(): void
    {
        $node = $this->node();
        $otherNode = $this->node(['name' => 'foreign-node']);
        $admin = User::create([
            'email' => 'agent-ws-admin@example.test',
            'password' => 'test', 'is_admin' => true,
            'token' => bin2hex(random_bytes(16)),
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $action = AgentAction::create([
            'request_id' => 'ops_native_action_0001',
            'admin_id' => $admin->id,
            'node_id' => $node->id,
            'action' => 'ops.kernel.status',
            'status' => AgentAction::STATUS_RUNNING,
            'risk_level' => 'operate',
            'started_at' => time(),
        ]);
        $sent = [];
        $conn = $this->connection($sent);
        $ws = app(NativeNodeWebSocket::class);

        try {
            $ws->connect($conn, $this->upgrade($node->id, 'native-ws-test-token'));
            $this->assertContains('ops.result', $sent[0]['data']['capabilities']);

            $ws->message($conn, NativeNodeFrame::encode('ops.result', [
                'node_id' => $otherNode->id,
                'request_id' => $action->request_id, 'ok' => true,
                'result' => ['status' => 'wrong-node'],
            ], 'foreign-ops'));
            $this->assertSame('NODE_NOT_FOUND', end($sent)['data']['code']);
            $this->assertSame(AgentAction::STATUS_RUNNING, $action->fresh()->status);

            $ws->message($conn, NativeNodeFrame::encode('ops.result', [
                'request_id' => $action->request_id, 'ok' => 'not-a-boolean',
            ], 'invalid-ops'));
            $this->assertSame('INVALID_OPERATION_RESULT', end($sent)['data']['code']);
            $this->assertSame(AgentAction::STATUS_RUNNING, $action->fresh()->status);

            $ws->message($conn, NativeNodeFrame::encode('ops.result', [
                'request_id' => $action->request_id, 'ok' => true,
                'result' => ['status' => 'healthy'],
            ], 'valid-ops'));
            $this->assertSame('ops.ack', end($sent)['event']);
            $this->assertSame('valid-ops', end($sent)['request_id']);
            $this->assertTrue(end($sent)['data']['accepted']);
            $this->assertSame(AgentAction::STATUS_SUCCEEDED, $action->fresh()->status);
            $this->assertSame(['status' => 'healthy'], $action->fresh()->result);

            // Duplicate delivery never overwrites the first committed result.
            $ws->message($conn, NativeNodeFrame::encode('ops.result', [
                'request_id' => $action->request_id, 'ok' => false,
                'error_code' => 'late_duplicate',
            ], 'duplicate-ops'));
            $this->assertSame('ops.ack', end($sent)['event']);
            $this->assertTrue(end($sent)['data']['accepted']);
            $this->assertSame(AgentAction::STATUS_SUCCEEDED, $action->fresh()->status);
            $this->assertSame(['status' => 'healthy'], $action->fresh()->result);

            $ws->message($conn, NativeNodeFrame::encode('ops.result', [
                'request_id' => 'ops_nonexistent_0001', 'ok' => true,
            ], 'unknown-ops'));
            $this->assertSame('ops.ack', end($sent)['event']);
            $this->assertFalse(end($sent)['data']['accepted']);
        } finally {
            NodeRegistry::remove((int) $node->id, $conn);
        }
    }

    public function test_machine_operation_result_respects_node_membership_and_timeout(): void
    {
        $machine = ServerMachine::create([
            'name' => 'ops-machine', 'token' => 'ops-machine-credential', 'is_active' => true,
        ]);
        $otherMachine = ServerMachine::create([
            'name' => 'ops-foreign', 'token' => 'ops-foreign-credential', 'is_active' => true,
        ]);
        $node = $this->node(['machine_id' => $machine->id]);
        $foreign = $this->node(['machine_id' => $otherMachine->id, 'name' => 'foreign']);
        $admin = User::create([
            'email' => 'agent-ws-machine@example.test',
            'password' => 'test', 'is_admin' => true,
            'token' => bin2hex(random_bytes(16)),
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $action = AgentAction::create([
            'request_id' => 'ops_machine_action_0001',
            'admin_id' => $admin->id, 'node_id' => $node->id,
            'action' => 'ops.kernel.status', 'status' => AgentAction::STATUS_RUNNING,
            'risk_level' => 'operate', 'started_at' => time() - 500,
        ]);
        $sent = [];
        $conn = $this->connection($sent);
        $ws = app(NativeNodeWebSocket::class);

        try {
            $ws->connect($conn, $this->upgrade(null, 'ops-machine-credential', $machine->id));
            $ws->message($conn, NativeNodeFrame::encode('ops.result', [
                'node_id' => $foreign->id,
                'request_id' => $action->request_id, 'ok' => true,
            ], 'wrong-membership'));
            $this->assertSame('NODE_NOT_FOUND', end($sent)['data']['code']);
            $this->assertSame(AgentAction::STATUS_RUNNING, $action->fresh()->status);

            $ws->message($conn, NativeNodeFrame::encode('ops.result', [
                'node_id' => $node->id,
                'request_id' => $action->request_id, 'ok' => true,
            ], 'too-late'));
            $this->assertSame('ops.ack', end($sent)['event']);
            $this->assertFalse(end($sent)['data']['accepted']);
            $this->assertSame(AgentAction::STATUS_TIMED_OUT, $action->fresh()->status);

            $machine->update(['token' => 'revoked-ops-credential']);
            $ws->message($conn, NativeNodeFrame::encode('ops.result', [
                'node_id' => $node->id,
                'request_id' => $action->request_id, 'ok' => true,
            ], 'revoked-ops'));
            $this->assertSame('UNAUTHORIZED', end($sent)['data']['code']);
        } finally {
            NodeRegistry::removeMachine((int) $machine->id, $conn);
            NodeRegistry::remove((int) $node->id, $conn);
        }
    }

    public function test_legacy_inbound_handler_class_has_been_removed(): void
    {
        $this->assertFileDoesNotExist(app_path('WebSocket/NodeEventHandlers.php'));
        $this->assertTrue(class_exists(\App\WebSocket\NativeNodePush::class));
        $this->assertFalse(class_exists(\App\WebSocket\NodeEventHandlers::class));
    }

    public function test_ws_and_http_reports_reuse_ledger_and_never_double_bill(): void
    {
        Bus::fake();
        Redis::shouldReceive('sadd')->zeroOrMoreTimes()->andReturn(1);
        $node = $this->node();
        $user = User::create([
            'email' => 'native-ws-account@example.test',
            'password' => 'test', 'token' => bin2hex(random_bytes(16)),
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'u' => 0, 'd' => 0, 'transfer_enable' => 1073741824,
        ]);
        $usage = [$user->id => [100, 200]];
        $sent = [];
        $conn = $this->connection($sent);
        $ws = app(NativeNodeWebSocket::class);
        $batchId = 'same-http-ws-000001';
        try {
            $ws->connect($conn, $this->upgrade($node->id, 'native-ws-test-token'));
            $ws->message($conn, NativeNodeFrame::encode('traffic.report', [
                'protocol_version' => 1, 'traffic_batch_id' => $batchId,
                'traffic' => $usage,
            ], 'report-001'));
            $this->assertSame('traffic.ack', end($sent)['event']);
            $this->assertSame('queued', end($sent)['data']['settlement']);
            $this->assertSame('report-001', end($sent)['request_id']);
            $this->assertSame(0, DB::table('v2_traffic_batch')->count());

            $this->postJson('/txapi/node/v1/report', [
                'protocol_version' => 1, 'traffic_batch_id' => $batchId,
                'traffic' => $usage,
            ], ['Authorization' => 'Bearer native-ws-test-token',
                'X-TX-Node-ID' => (string) $node->id])
                ->assertStatus(202)->assertJsonPath('data.settlement', 'queued');
            Bus::assertDispatchedTimes(TrafficBatchJob::class, 2);

            for ($i = 0; $i < 2; $i++) {
                (new TrafficBatchJob(
                    ['id' => $node->id, 'rate' => 1],
                    $usage, $node->type, strtotime(date('Y-m-d')), $batchId
                ))->handle();
            }
            $this->assertSame(100, (int) $user->fresh()->u);
            $this->assertSame(200, (int) $user->fresh()->d);
            $this->assertSame(1, DB::table('v2_traffic_batch')->count());
        } finally {
            NodeRegistry::remove((int) $node->id, $conn);
        }
    }
}
