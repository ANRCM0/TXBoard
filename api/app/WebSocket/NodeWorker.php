<?php

namespace App\WebSocket;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

class NodeWorker
{
    private const AUTH_TIMEOUT = 10;
    private const PING_INTERVAL = 55;

    public const HEARTBEAT_CACHE_KEY = 'ws_server:heartbeat';
    private const HEARTBEAT_INTERVAL = 10;
    private const HEARTBEAT_TTL = 30;

    private Worker $worker;

    private array $handlers = [
        'pong' => [NodeEventHandlers::class, 'handlePong'],
        'node.status' => [NodeEventHandlers::class, 'handleNodeStatus'],
        'report.devices' => [NodeEventHandlers::class, 'handleDeviceReport'],
        'request.devices' => [NodeEventHandlers::class, 'handleDeviceRequest'],
        'ops.result' => [NodeEventHandlers::class, 'handleOpsResult'],
    ];

    public function __construct(string $host, int $port)
    {
        $this->worker = new Worker("websocket://{$host}:{$port}");
        $this->worker->count = 1;
        $this->worker->name = 'txboard-ws-server';
    }

    public function run(): void
    {
        $this->setupLogging();
        $this->setupCallbacks();
        Worker::runAll();
    }

    private function setupLogging(): void
    {
        $logPath = storage_path('logs');
        if (!is_dir($logPath)) {
            mkdir($logPath, 0777, true);
        }
        Worker::$logFile = $logPath . '/txboard-ws-server.log';
        Worker::$pidFile = $logPath . '/txboard-ws-server.pid';
    }

    private function setupCallbacks(): void
    {
        $this->worker->onWorkerStart = [$this, 'onWorkerStart'];
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
    }

    public function onWorkerStart(Worker $worker): void
    {
        Log::info("[WS] Worker started, pid={$worker->id}");
        $this->subscribeRedis();
        $this->setupTimers();
    }

    private function setupTimers(): void
    {
        Cache::put(self::HEARTBEAT_CACHE_KEY, time(), self::HEARTBEAT_TTL);
        Timer::add(self::HEARTBEAT_INTERVAL, function () {
            Cache::put(self::HEARTBEAT_CACHE_KEY, time(), self::HEARTBEAT_TTL);
        });

        Timer::add(self::PING_INTERVAL, function () {
            $seen = [];

            foreach (NodeRegistry::getConnectedNodeIds() as $nodeId) {
                $conn = NodeRegistry::get($nodeId);
                if ($conn) {
                    if (!empty($conn->txnodeNative)
                        && time() - (int) ($conn->lastPongAt ?? 0) >= NodeSyncService::WS_TTL_SECONDS) {
                        $conn->close();
                        continue;
                    }
                    $oid = spl_object_id($conn);
                    if (!isset($seen[$oid])) {
                        $seen[$oid] = true;
                        $conn->send(!empty($conn->txnodeNative)
                            ? NativeNodeFrame::encode('heartbeat.ping', ['sent_at' => time()])
                            : json_encode(['event' => 'ping']));
                    }
                }
            }

            foreach (NodeRegistry::getConnectedMachineIds() as $machineId) {
                $conn = NodeRegistry::getMachine($machineId);
                if ($conn) {
                    if (!empty($conn->txnodeNative)
                        && time() - (int) ($conn->lastPongAt ?? 0) >= NodeSyncService::WS_TTL_SECONDS) {
                        $conn->close();
                        continue;
                    }
                    $oid = spl_object_id($conn);
                    if (!isset($seen[$oid])) {
                        $seen[$oid] = true;
                        $conn->send(!empty($conn->txnodeNative)
                            ? NativeNodeFrame::encode('heartbeat.ping', ['sent_at' => time()])
                            : json_encode(['event' => 'ping']));
                    }
                }
            }
        });

        // Direct authoritative reconciliation is independent of Redis pub/sub.
        // Lost events or a Redis reconnect are corrected on the next sweep.
        Timer::add(300, function () {
            $this->reconcileConnections();
        });

        Timer::add(10, function () {
            $pendingNodeIds = Redis::spop('device:push_pending_nodes', 100);
            if (empty($pendingNodeIds)) {
                return;
            }

            $service = app(DeviceStateService::class);
            foreach ($pendingNodeIds as $nodeId) {
                $nodeId = (int) $nodeId;
                if (NodeRegistry::get($nodeId) !== null) {
                    NodeEventHandlers::pushDeviceStateToNode($nodeId, $service);
                }
            }
        });
    }

    private function reconcileConnections(): void
    {
        foreach (NodeRegistry::getConnectedMachineIds() as $machineId) {
            $conn = NodeRegistry::getMachine($machineId);
            if (!$conn) {
                continue;
            }

            try {
                $machine = ServerMachine::find($machineId);
                if (!$machine || !$machine->is_active
                    || (!empty($conn->txnodeNative)
                        && !hash_equals((string) ($conn->txnodeCredentialHash ?? ''),
                            hash('sha256', (string) $machine->token)))) {
                    $conn->close();
                    continue;
                }
                $nodes = ServerService::getMachineNodes($machine);
                $newIds = $nodes->pluck('id')->map(fn ($id) => (int) $id)->all();
                $oldIds = $conn->machineNodeIds ?? [];
                if ($oldIds !== $newIds) {
                    NodeRegistry::refreshMachineNodes($machineId, $newIds);
                    NodeRegistry::sendMachine($machineId, 'sync.nodes', [
                        'nodes' => $nodes->map(fn ($node) => [
                            'id' => $node->id, 'type' => $node->type, 'name' => $node->name,
                        ])->all(),
                    ]);
                    foreach (array_diff($oldIds, $newIds) as $removedId) {
                        NodeSyncService::markNodeOffline((int) $removedId);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[WS] Machine reconciliation failed', [
                    'machine_id' => $machineId, 'error' => $e->getMessage(),
                ]);
            }
        }

        foreach (NodeRegistry::getConnectedNodeIds() as $nodeId) {
            try {
                $conn = NodeRegistry::get($nodeId);
                if (!$conn) {
                    continue;
                }
                if ((time() - (int) ($conn->lastPongAt ?? 0)) >= NodeSyncService::WS_TTL_SECONDS) {
                    $conn->close();
                    continue;
                }
                $node = Server::find($nodeId);
                if (!$node || !$node->enabled) {
                    $conn->close();
                    continue;
                }
                if (!empty($conn->txnodeNative) && empty($conn->machineId)) {
                    // Machine sockets use their machine token (checked in
                    // the machine reconciliation above), not server_token.
                    $configured = (string) admin_setting('server_token', '');
                    if ($configured === '' ||
                        !hash_equals((string) ($conn->txnodeCredentialHash ?? ''),
                            hash('sha256', $configured))) {
                        $conn->close();
                        continue;
                    }
                }
                NodeEventHandlers::pushFullSync($conn, $node);
            } catch (\Throwable $e) {
                Log::warning('[WS] Node full resync failed', [
                    'node_id' => $nodeId, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function onConnect(TcpConnection $conn): void
    {
        $conn->authTimer = Timer::add(self::AUTH_TIMEOUT, function () use ($conn) {
            if (empty($conn->nodeId) && empty($conn->machineNodeIds)) {
                $conn->close(json_encode([
                    'event' => 'error',
                    'data' => ['message' => 'auth timeout'],
                ]));
            }
        }, [], false);
    }

    public function onWebSocketConnect(TcpConnection $conn, $httpMessage): void
    {
        $nativePath = $httpMessage instanceof \Workerman\Protocols\Http\Request
            ? $httpMessage->path() : parse_url((string) $httpMessage, PHP_URL_PATH);
        if ($nativePath === NativeNodeWebSocket::PATH) {
            if (isset($conn->authTimer)) {
                Timer::del($conn->authTimer);
            }
            if (!$httpMessage instanceof \Workerman\Protocols\Http\Request) {
                $conn->close(NativeNodeFrame::encode('error', ['code' => 'INVALID_UPGRADE']));
                return;
            }
            app(NativeNodeWebSocket::class)->connect($conn, $httpMessage);
            return;
        }

        // Only the native versioned WS handshake may enter this worker.
        // Do not accept the removed /ws machine/node query-token protocol.
        $conn->close(NativeNodeFrame::encode('error', ['code' => 'UNKNOWN_WS_PATH']));
        return;

        $queryString = '';
        if (is_string($httpMessage)) {
            $queryString = parse_url($httpMessage, PHP_URL_QUERY) ?? '';
        } elseif ($httpMessage instanceof \Workerman\Protocols\Http\Request) {
            $queryString = $httpMessage->queryString();
        }

        parse_str($queryString, $params);

        if (isset($conn->authTimer)) {
            Timer::del($conn->authTimer);
        }

        // 判断认证模式
        if (!empty($params['machine_id'])) {
            $this->authenticateMachine($conn, $params);
        } else {
            $this->authenticateNode($conn, $params);
        }
    }

    /**
     * 旧模式：单节点认证
     */
    private function authenticateNode(TcpConnection $conn, array $params): void
    {
        $token = $params['token'] ?? '';
        $nodeId = (int) ($params['node_id'] ?? 0);

        $serverToken = admin_setting('server_token', '');
        if ($token === '' || $serverToken === '' || !hash_equals($serverToken, $token)) {
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'invalid token'],
            ]));
            return;
        }

        $node = ServerService::getServer($nodeId, null);
        if (!$node) {
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'node not found'],
            ]));
            return;
        }

        $conn->nodeId = $nodeId;
        $conn->lastPongAt = time();
        NodeRegistry::add($nodeId, $conn);
        NodeSyncService::markNodeOnline($nodeId);

        app(DeviceStateService::class)->clearAllNodeDevices($nodeId);

        Log::debug("[WS] Node#{$nodeId} connected", [
            'remote' => $conn->getRemoteIp(),
            'total' => NodeRegistry::count(),
        ]);

        $conn->send(json_encode([
            'event' => 'auth.success',
            'data' => ['node_id' => $nodeId],
        ]));

        NodeEventHandlers::pushFullSync($conn, $node);
    }

    /**
     * 新模式：机器认证，自动注册该机器下所有已启用节点
     */
    private function authenticateMachine(TcpConnection $conn, array $params): void
    {
        $machineId = (int) ($params['machine_id'] ?? 0);
        $token = $params['token'] ?? '';

        $machine = ServerMachine::where('id', $machineId)
            ->where('token', $token)
            ->first();

        if (!$machine || !$machine->is_active) {
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'invalid machine credentials'],
            ]));
            return;
        }

        $nodes = ServerService::getMachineNodes($machine);

        $machine->forceFill(['last_seen_at' => now()->timestamp])->saveQuietly();
        $conn->lastPongAt = time();
        NodeRegistry::addMachine($machineId, $conn);
        NodeSyncService::markMachineOnline($machineId);

        // 把同一个连接注册到该机器下所有节点
        $nodeIds = [];
        $deviceService = app(DeviceStateService::class);
        foreach ($nodes as $node) {
            NodeRegistry::add($node->id, $conn);
            NodeSyncService::markNodeOnline((int) $node->id);
            $deviceService->clearAllNodeDevices($node->id);
            $nodeIds[] = $node->id;
        }

        // 连接上记录所属机器和节点列表
        $conn->machineId = $machineId;
        $conn->machineNodeIds = $nodeIds;

        Log::debug("[WS] Machine#{$machineId} connected, nodes: " . implode(',', $nodeIds), [
            'remote' => $conn->getRemoteIp(),
            'total' => NodeRegistry::count(),
            'machines' => NodeRegistry::machineCount(),
        ]);

        $conn->send(json_encode([
            'event' => 'auth.success',
            'data' => [
                'machine_id' => $machineId,
                'node_ids' => $nodeIds,
            ],
        ]));

        // 为每个节点推送完整同步
        foreach ($nodes as $node) {
            NodeEventHandlers::pushFullSync($conn, $node);
        }
    }

    public function onMessage(TcpConnection $conn, $data): void
    {
        if (!empty($conn->txnodeNative)) {
            app(NativeNodeWebSocket::class)->message($conn, $data);
            return;
        }
        $msg = json_decode($data, true);
        if (!is_array($msg)) {
            return;
        }

        $event = $msg['event'] ?? '';

        // 机器连接：从消息中读取 node_id 来分派到具体节点。
        // machineId is authoritative here because a valid Machine may
        // temporarily host zero nodes and still needs heartbeat/update control.
        if (!empty($conn->machineId)) {
            if ($event === 'pong') {
                $conn->lastPongAt = time();
                if (!empty($conn->machineId)) {
                    NodeSyncService::markMachineOnline((int) $conn->machineId);
                }
                foreach ($conn->machineNodeIds as $nid) {
                    NodeSyncService::markNodeOnline((int) $nid);
                }
                return;
            }

            $nodeId = (int) ($msg['data']['node_id'] ?? 0);
            if ($nodeId <= 0 || !in_array($nodeId, $conn->machineNodeIds, true)) {
                return;
            }
            if (isset($this->handlers[$event])) {
                $handler = $this->handlers[$event];
                $handler($conn, $nodeId, $msg['data'] ?? []);
            }
            return;
        }

        // 旧模式：单节点
        $nodeId = $conn->nodeId ?? null;
        if ($event === 'pong' && $nodeId) {
            $conn->lastPongAt = time();
        }
        if (isset($this->handlers[$event]) && $nodeId) {
            $handler = $this->handlers[$event];
            $handler($conn, $nodeId, $msg['data'] ?? []);
        }
    }

    public function onClose(TcpConnection $conn): void
    {
        $service = app(DeviceStateService::class);

        // 机器模式：清理所有关联节点。machineId also covers empty machines.
        if (!empty($conn->machineId)) {
            $machineId = $conn->machineId ?? 'unknown';
            // A replaced connection must never clear its successor's state.
            if (NodeRegistry::getMachine((int) $conn->machineId) !== $conn) {
                return;
            }
            foreach ($conn->machineNodeIds as $nodeId) {
                if (NodeRegistry::get((int) $nodeId) !== $conn) {
                    continue;
                }
                NodeRegistry::remove($nodeId, $conn);
                NodeSyncService::markNodeOffline((int) $nodeId);

                $affectedUserIds = $service->clearAllNodeDevices($nodeId);
                foreach ($affectedUserIds as $userId) {
                    $service->notifyUpdate($userId);
                }
            }

            if (!empty($conn->machineId)) {
                NodeRegistry::removeMachine((int) $conn->machineId, $conn);
                NodeSyncService::markMachineOffline((int) $conn->machineId);
            }

            Log::debug("[WS] Machine#{$machineId} disconnected", [
                'nodes' => $conn->machineNodeIds,
                'total' => NodeRegistry::count(),
                'machines' => NodeRegistry::machineCount(),
            ]);
            return;
        }

        // 旧模式：单节点
        if (!empty($conn->nodeId)) {
            $nodeId = $conn->nodeId;
            if (NodeRegistry::get((int) $nodeId) !== $conn) {
                return;
            }
            NodeRegistry::remove($nodeId, $conn);
            NodeSyncService::markNodeOffline((int) $nodeId);

            $affectedUserIds = $service->clearAllNodeDevices($nodeId);
            foreach ($affectedUserIds as $userId) {
                $service->notifyUpdate($userId);
            }

            Log::debug("[WS] Node#{$nodeId} disconnected", [
                'total' => NodeRegistry::count(),
                'affected_users' => count($affectedUserIds),
            ]);
        }
    }

    private function subscribeRedis(): void
    {
        $host = config('database.redis.default.host', '127.0.0.1');
        $port = config('database.redis.default.port', 6379);

        if (str_starts_with($host, '/')) {
            $redisUri = "unix://{$host}";
        } else {
            $redisUri = "redis://{$host}:{$port}";
        }

        $redis = new \Workerman\Redis\Client($redisUri);

        $password = config('database.redis.default.password');
        if ($password) {
            $redis->auth($password);
        }

        $prefix = config('database.redis.options.prefix', '');
        $channel = $prefix . 'node:push';

        $redis->subscribe([$channel], function ($chan, $message) {
            $payload = json_decode($message, true);
            if (!is_array($payload)) {
                return;
            }

            $event = $payload['event'] ?? '';
            $data = $payload['data'] ?? [];

            // Machine-level events (e.g., sync.nodes)
            $machineId = $payload['machine_id'] ?? null;
            if ($machineId && $event) {
                // Update server-side registry when node membership changes
                if ($event === 'sync.nodes') {
                    $nodeIds = array_map('intval', array_column($data['nodes'] ?? [], 'id'));
                    NodeRegistry::refreshMachineNodes((int) $machineId, $nodeIds);
                }

                $sent = NodeRegistry::sendMachine((int) $machineId, $event, $data);
                if ($sent) {
                    Log::debug("[WS] Pushed {$event} to machine#{$machineId}");
                }
                return;
            }

            // Per-node events
            $nodeId = $payload['node_id'] ?? null;
            if (!$nodeId || !$event) {
                return;
            }

            $sent = NodeRegistry::send((int) $nodeId, $event, $data);
            if ($sent) {
                Log::debug("[WS] Pushed {$event} to node#{$nodeId}");
            }
        });

        Log::info("[WS] Subscribed to Redis channel: {$channel}");
    }
}
