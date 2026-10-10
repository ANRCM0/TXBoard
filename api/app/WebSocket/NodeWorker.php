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
                    if (time() - (int) ($conn->lastPongAt ?? 0) >= NodeSyncService::WS_TTL_SECONDS) {
                        $conn->close();
                        continue;
                    }
                    $oid = spl_object_id($conn);
                    if (!isset($seen[$oid])) {
                        $seen[$oid] = true;
                        $conn->send(NativeNodeFrame::encode('heartbeat.ping', ['sent_at' => time()]));
                    }
                }
            }

            foreach (NodeRegistry::getConnectedMachineIds() as $machineId) {
                $conn = NodeRegistry::getMachine($machineId);
                if ($conn) {
                    if (time() - (int) ($conn->lastPongAt ?? 0) >= NodeSyncService::WS_TTL_SECONDS) {
                        $conn->close();
                        continue;
                    }
                    $oid = spl_object_id($conn);
                    if (!isset($seen[$oid])) {
                        $seen[$oid] = true;
                        $conn->send(NativeNodeFrame::encode('heartbeat.ping', ['sent_at' => time()]));
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
                    NativeNodePush::pushDeviceStateToNode($nodeId, $service);
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
                    || !hash_equals((string) ($conn->txnodeCredentialHash ?? ''),
                            hash('sha256', (string) $machine->token))) {
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
                if (empty($conn->machineId)) {
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
                NativeNodePush::pushFullSync($node);
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
            if (empty($conn->txnodeNative)) {
                $conn->close(NativeNodeFrame::encode('error', ['code' => 'AUTH_TIMEOUT']));
            }
        }, [], false);
    }

    /** Only versioned TX-Node connections are accepted; query-token upgrades are gone. */
    public function onWebSocketConnect(TcpConnection $conn, $httpMessage): void
    {
        if (!$httpMessage instanceof \Workerman\Protocols\Http\Request
            || $httpMessage->path() !== NativeNodeWebSocket::PATH) {
            $conn->close(NativeNodeFrame::encode('error', ['code' => 'UNKNOWN_WS_PATH']));
            return;
        }
        if (isset($conn->authTimer)) {
            Timer::del($conn->authTimer);
        }
        app(NativeNodeWebSocket::class)->connect($conn, $httpMessage);
    }

    /** Reject unauthenticated frames; only the native versioned parser is reachable. */
    public function onMessage(TcpConnection $conn, $data): void
    {
        if (empty($conn->txnodeNative)) {
            $conn->close(NativeNodeFrame::encode('error', ['code' => 'UNAUTHORIZED']));
            return;
        }
        app(NativeNodeWebSocket::class)->message($conn, $data);
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

        // Native standalone node
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
