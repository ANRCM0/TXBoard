<?php

namespace App\WebSocket;

use App\Domains\Network\NativeNodeReport;
use App\Domains\Network\NodeReportError;
use App\Services\AgentOps\AgentActionService;
use App\Http\Middleware\TxNodeAuth;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\NodeRegistry;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Workerman\Connection\TcpConnection;

/**
 * TXBoard-native v1 frames on the existing Workerman listener.
 * All authorization is through the same TxNodeAuth policy as native HTTP.
 */
final class NativeNodeWebSocket
{
    public const PATH = '/txapi/node/v1/ws';

    public function connect(TcpConnection $conn, \Workerman\Protocols\Http\Request $upgrade): void
    {
        if (!config('node_ws.native_enabled', false)) {
            $this->reject($conn, 'WS_DISABLED');
            return;
        }

        // Workerman handles the HTTP Upgrade itself. Never accept bearer
        // credentials through query parameters; retired paths are not recognized.
        $query = $upgrade->queryString();
        if ($query !== '') {
            $this->reject($conn, 'INVALID_UPGRADE');
            return;
        }
        $headers = ['HTTP_AUTHORIZATION' => (string) $upgrade->header('authorization', '')];
        foreach (['x-tx-node-id' => 'HTTP_X_TX_NODE_ID',
                  'x-tx-machine-id' => 'HTTP_X_TX_MACHINE_ID'] as $header => $serverKey) {
            $value = $upgrade->header($header);
            if ($value !== null && $value !== '') {
                $headers[$serverKey] = (string) $value;
            }
        }
        $request = Request::create('/txapi/node/v1/handshake', 'POST', [], [], [], $headers);
        $result = app(TxNodeAuth::class)->handle($request, static fn (Request $verified) => $verified);
        if ($result instanceof JsonResponse) {
            $this->reject($conn, 'UNAUTHORIZED');
            return;
        }

        $node = $request->attributes->get('txnode.node');
        $machine = $request->attributes->get('txnode.machine');
        $bearer = $request->bearerToken();
        $conn->txnodeNative = true;
        $conn->txnodeCredentialHash = hash('sha256', $bearer);
        $conn->lastPongAt = time();

        if ($machine instanceof ServerMachine) {
            $conn->machineId = (int) $machine->id;
            $nodes = ServerService::getMachineNodes($machine);
            $conn->machineNodeIds = $nodes->pluck('id')->map(fn ($id) => (int) $id)->all();
            NodeRegistry::addMachine((int) $machine->id, $conn);
            NodeSyncService::markMachineOnline((int) $machine->id);
            foreach ($nodes as $item) {
                NodeRegistry::add((int) $item->id, $conn);
                NodeSyncService::markNodeOnline((int) $item->id);
            }
            // A machine socket multiplexes all nodes, even if it was
            // opened with a specific node ID. Node IDs are checked again
            // per inbound event and may be revoked during a connection.
            $this->ready($conn, 'machine', null, (int) $machine->id);
            NodeRegistry::sendMachine((int) $machine->id, 'sync.nodes', [
                'nodes' => $nodes->map(static fn ($item) => [
                    'id' => (int) $item->id, 'type' => $item->type, 'name' => $item->name,
                ])->values()->all(),
            ]);
            foreach ($nodes as $item) {
                NativeNodePush::pushFullSync($item);
            }
        } else {
            $conn->nodeId = (int) $node->id;
            NodeRegistry::add((int) $node->id, $conn);
            NodeSyncService::markNodeOnline((int) $node->id);
            $this->ready($conn, 'node', (int) $node->id, null);
            NativeNodePush::pushFullSync($node);
        }
    }

    public function message(TcpConnection $conn, mixed $raw): void
    {
        $requestId = null;
        try {
            $frame = NativeNodeFrame::parse($raw);
            $requestId = $frame['request_id'];
            $event = $frame['event'];
            if ($event === 'heartbeat.pong' || $event === 'heartbeat.ping') {
                $this->checkCredentials($conn);
                $conn->lastPongAt = time();
                if (!empty($conn->machineId)) {
                    NodeSyncService::markMachineOnline((int) $conn->machineId);
                    foreach ($conn->machineNodeIds ?? [] as $id) {
                        NodeSyncService::markNodeOnline((int) $id);
                    }
                } else {
                    NodeSyncService::markNodeOnline((int) $conn->nodeId);
                }
                $this->send($conn, 'heartbeat.ack', ['accepted' => true], $requestId);
                return;
            }

            if (!in_array($event, ['traffic.report', 'sync.request', 'ops.result'], true)) {
                throw new \InvalidArgumentException('UNKNOWN_EVENT');
            }
            // Every inbound node-scoped event is authorized against the live
            // machine membership / server-token state, not a client-supplied ID.
            $node = $this->scopedNode($conn, $frame['data']);
            if ($event === 'ops.result') {
                $data = $frame['data'];
                $validated = Validator::make($data, [
                    'request_id' => ['required', 'string', 'max:64', 'regex:/^ops_[A-Za-z0-9_-]{8,60}$/D'],
                    'ok' => ['required', 'boolean'],
                    'result' => ['sometimes', 'array'],
                    'message' => ['sometimes', 'nullable', 'string', 'max:2048'],
                    'error_code' => ['sometimes', 'nullable', 'string', 'max:64'],
                    'node_id' => ['sometimes', 'integer', 'min:1'],
                ]);
                if ($validated->fails()) {
                    throw new \InvalidArgumentException('INVALID_OPERATION_RESULT');
                }
                $payload = $validated->validated();
                // AgentAction.result is a MySQL TEXT field, not a 1 MiB blob.
                if (strlen(json_encode($payload['result'] ?? [], JSON_THROW_ON_ERROR)) > 60000) {
                    throw new \InvalidArgumentException('INVALID_OPERATION_RESULT');
                }
                $accepted = app(AgentActionService::class)->handleNodeResult((int) $node->id, $payload);
                $this->send($conn, 'ops.ack', [
                    'accepted' => $accepted,
                    'node_id' => (int) $node->id,
                    'action_request_id' => $payload['request_id'],
                ], $requestId);
                return;
            }
            if ($event === 'sync.request') {
                NativeNodePush::pushFullSync($node);
                $this->send($conn, 'sync.ack', ['node_id' => (int) $node->id], $requestId);
                return;
            }
            // The HTTP path and WS path call the same validation and
            // dispatch method. Acknowledgement MUST NOT claim settlement.
            $report = app(NativeNodeReport::class)->submit($node, $frame['data']);
            $this->send($conn, 'traffic.ack', $report + ['node_id' => (int) $node->id], $requestId);
        } catch (NodeReportError $e) {
            $this->sendError($conn, $e->errorCode, $requestId);
        } catch (\InvalidArgumentException $e) {
            $code = in_array($e->getMessage(), [
                'FRAME_TOO_LARGE', 'INVALID_FRAME', 'UNKNOWN_EVENT',
                'NODE_NOT_FOUND', 'NODE_ID_REQUIRED', 'UNAUTHORIZED',
                'INVALID_OPERATION_RESULT',
            ], true) ? $e->getMessage() : 'INVALID_FRAME';
            $this->sendError($conn, $code, $requestId);
            if ($code === 'UNAUTHORIZED') {
                $conn->close();
            }
        } catch (\Throwable $e) {
            Log::error('[TXNode WS] Native operation failed', ['exception' => $e::class]);
            $this->sendError($conn, 'SERVER_ERROR', $requestId);
        }
    }

    private function checkCredentials(TcpConnection $conn): void
    {
        if (!empty($conn->machineId)) {
            $machine = ServerMachine::query()->find((int) $conn->machineId);
            if (!$machine || !$machine->is_active
                || !hash_equals((string) ($conn->txnodeCredentialHash ?? ''),
                    hash('sha256', (string) $machine->token))) {
                throw new \InvalidArgumentException('UNAUTHORIZED');
            }
        } else {
            $currentToken = (string) admin_setting('server_token', '');
            if ($currentToken === ''
                || !hash_equals((string) ($conn->txnodeCredentialHash ?? ''),
                    hash('sha256', $currentToken))) {
                throw new \InvalidArgumentException('UNAUTHORIZED');
            }
        }
    }

    private function scopedNode(TcpConnection $conn, array $data): Server
    {
        $this->checkCredentials($conn);
        if (!empty($conn->machineId)) {
            $id = $data['node_id'] ?? null;
            if (!is_int($id) || $id <= 0 || !in_array($id, $conn->machineNodeIds ?? [], true)) {
                throw new \InvalidArgumentException('NODE_NOT_FOUND');
            }
            $node = Server::query()->whereKey($id)->where('machine_id', $conn->machineId)
                ->where('enabled', true)->first();
        } else {
            $id = (int) ($conn->nodeId ?? 0);
            if (isset($data['node_id']) && $data['node_id'] !== $id) {
                throw new \InvalidArgumentException('NODE_NOT_FOUND');
            }
            $node = Server::query()->whereKey($id)->where('enabled', true)->first();
        }
        if (!$node) {
            throw new \InvalidArgumentException('NODE_NOT_FOUND');
        }
        return $node;
    }

    private function ready(TcpConnection $conn, string $mode, ?int $nodeId, ?int $machineId): void
    {
        $this->send($conn, 'session.ready', [
            'mode' => $mode,
            'node_id' => $nodeId,
            'machine_id' => $machineId,
            'heartbeat_interval_seconds' => 55,
            'heartbeat_timeout_seconds' => NodeSyncService::WS_TTL_SECONDS,
            'capabilities' => ['sync.config', 'sync.users', 'sync.user.delta',
                'sync.nodes', 'sync.devices', 'traffic.report', 'traffic.ack', 'ops.result',
                'ops.ack', 'heartbeat.ping'],
        ]);
    }

    public function send(TcpConnection $conn, string $event, array $data, ?string $requestId = null): void
    {
        $conn->send(NativeNodeFrame::encode($event, $data, $requestId));
    }

    private function sendError(TcpConnection $conn, string $code, ?string $requestId): void
    {
        $this->send($conn, 'error', ['code' => $code, 'message' => 'Request rejected'], $requestId);
    }

    private function reject(TcpConnection $conn, string $code): void
    {
        $conn->close(NativeNodeFrame::encode('error', ['code' => $code, 'message' => 'Connection rejected']));
    }
}
