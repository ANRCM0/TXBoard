<?php

namespace App\Domains\Network;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\NodeRegistry;
use App\Services\NodeSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Workerman\Connection\TcpConnection;

/** Short-lived receipts for two explicit operations on the Node connection owner. */
final class NativeNodeConnections
{
    public const REQUEST = 'ops.node.connections.request';
    public const RESULT = 'ops.node.connections.result';
    private const TTL = 60;

    private static function key(string $id): string { return 'native_connections:'.$id; }

    private function scope(Server $node): ?array
    {
        if (!$node->enabled) return null;
        $machineId = (int) ($node->machine_id ?? 0);
        $machineHash = null;
        if ($machineId > 0) {
            $machine = ServerMachine::query()->find($machineId);
            if ($machine && $machine->is_active && is_string($machine->token) && $machine->token !== '') {
                $machineHash = hash('sha256', $machine->token);
            }
        }
        // Native auth also permits a standalone node under the server token,
        // including a node which is grouped under a Machine in Core metadata.
        $token = (string) admin_setting('server_token', '');
        $nodeHash = $token === '' ? null : hash('sha256', $token);
        return $machineHash === null && $nodeHash === null ? null : [$machineId, $machineHash, $nodeHash];
    }

    public function request(Server $node, int $actorId, string $action, array $input): array
    {
        $scope = $this->scope($node);
        if ($scope === null || !NodeSyncService::isNodeOnline((int) $node->id)) {
            throw new \DomainException('NODE_CONNECTIONS_UNAVAILABLE');
        }
        // Shared across administrators; the Node has a separate bounded mailbox.
        $rateKey = 'native_connections:rate:'.$node->id;
        if (RateLimiter::tooManyAttempts($rateKey, 24)) throw new \DomainException('NODE_CONNECTIONS_RATE_LIMITED');
        RateLimiter::hit($rateKey, 60);
        $id = 'nco_'.bin2hex(random_bytes(16));
        $command = ['node_id' => (int) $node->id, 'request_id' => $id, 'action' => $action, 'deadline' => now()->getTimestamp() + 15];
        if ($action === 'list') {
            $command += ['after' => $input['after'] ?? '', 'limit' => $input['limit'] ?? 50];
        } elseif ($action === 'close') {
            $command['connection_id'] = $input['connection_id'];
        } else {
            throw new \InvalidArgumentException('INVALID_CONNECTION_ACTION');
        }
        $entry = ['actor_id' => $actorId, 'node_id' => (int) $node->id, 'scope' => $scope,
            'command' => $command, 'socket_epoch' => null, 'status' => 'pending', 'page' => null];
        Cache::put(self::key($id), $entry, self::TTL);
        if (!NodeSyncService::push((int) $node->id, self::REQUEST, ['request_id' => $id])) {
            $entry['status'] = 'unavailable';
            Cache::put(self::key($id), $entry, self::TTL);
        }
        return $this->project($entry);
    }

    public function read(Server $node, int $actorId, string $id): ?array
    {
        $entry = Cache::get(self::key($id));
        if (!is_array($entry) || $entry['node_id'] !== (int) $node->id || $entry['actor_id'] !== $actorId) return null;
        if ($this->scope($node) !== $entry['scope']) {
            $entry['status'] = 'unavailable';
            $entry['page'] = null;
        }
        return $this->project($entry);
    }

    private function project(array $entry): array
    {
        $command = $entry['command'];
        $status = $entry['status'];
        if ($status === 'pending' && $command['deadline'] <= now()->getTimestamp()) $status = 'unknown';
        $page = $entry['page'];
        return ['request_id' => $command['request_id'], 'node_id' => (string) $entry['node_id'],
            'action' => $command['action'], 'deadline' => $command['deadline'], 'status' => $status,
            'connections' => $page['connections'] ?? [], 'total' => $page['total'] ?? 0, 'next' => $page['next'] ?? '',
            'connection_id' => $command['connection_id'] ?? ''];
    }

    /** Redis carries only a receipt ID. The current receipt and live scope supply the command. */
    public function dispatch(Server $node, TcpConnection $conn, mixed $id): void
    {
        if (!is_string($id) || !preg_match('/^nco_[a-f0-9]{32}$/D', $id)) return;
        $entry = Cache::get(self::key($id));
        if (!is_array($entry) || $entry['node_id'] !== (int) $node->id || $entry['status'] !== 'pending'
            || $entry['command']['deadline'] <= now()->getTimestamp() || $this->scope($node) !== $entry['scope']
            || NodeRegistry::get((int) $node->id) !== $conn
            || !is_string($conn->txnodeConnectionEpoch ?? null)) return;
        $machineId = (int) ($conn->machineId ?? 0);
        $hash = $machineId > 0 ? ($machineId === $entry['scope'][0] ? $entry['scope'][1] : null) : $entry['scope'][2];
        if (!is_string($hash) || !hash_equals($hash, (string) ($conn->txnodeCredentialHash ?? ''))) return;
        if (!Cache::add(self::key($id).':dispatched', true, self::TTL)) return;
        $entry['socket_epoch'] = $conn->txnodeConnectionEpoch;
        Cache::put(self::key($id), $entry, self::TTL);
        NodeRegistry::send((int) $node->id, self::REQUEST, $entry['command']);
    }

    /** Called only after the existing WS path rechecks credentials and Machine membership. */
    public function accept(Server $node, TcpConnection $conn, array $data): void
    {
        $id = $data['request_id'] ?? null;
        if (!is_string($id) || !preg_match('/^nco_[a-f0-9]{32}$/D', $id)) throw new \InvalidArgumentException('INVALID_FRAME');
        $entry = Cache::get(self::key($id));
        if (!is_array($entry) || $entry['node_id'] !== (int) $node->id || ($data['node_id'] ?? null) !== (int) $node->id
            || $entry['command']['deadline'] <= now()->getTimestamp() || $entry['socket_epoch'] === null
            || $entry['socket_epoch'] !== ($conn->txnodeConnectionEpoch ?? null)
            || NodeRegistry::get((int) $node->id) !== $conn || $this->scope($node) !== $entry['scope']
            || ($data['action'] ?? null) !== $entry['command']['action']) throw new \InvalidArgumentException('INVALID_FRAME');
        if (array_diff(array_keys($data), ['node_id', 'request_id', 'action', 'status', 'page']) !== []) throw new \InvalidArgumentException('INVALID_FRAME');
        $status = $data['status'] ?? null;
        $allowed = $entry['command']['action'] === 'list' ? ['complete', 'invalid', 'not_found', 'expired', 'unavailable']
            : ['closed', 'not_found', 'invalid', 'expired', 'unavailable'];
        if (!in_array($status, $allowed, true)) throw new \InvalidArgumentException('INVALID_FRAME');
        $page = null;
        if ($status === 'complete') {
            $page = $this->validatePage($data['page'] ?? null, $entry['command']['limit']);
        } elseif (array_key_exists('page', $data)) {
            throw new \InvalidArgumentException('INVALID_FRAME');
        }
        if ($entry['status'] !== 'pending') {
            if ($entry['status'] !== $status || $entry['page'] !== $page) throw new \InvalidArgumentException('INVALID_FRAME');
            return;
        }
        // First valid result wins. A duplicate cannot replace a terminal receipt.
        if (!Cache::add(self::key($id).':completed', true, self::TTL)) return;
        $entry['status'] = $status;
        $entry['page'] = $page;
        Cache::put(self::key($id), $entry, self::TTL);
    }

    private function validatePage(mixed $page, int $limit): array
    {
        if (!is_array($page) || array_diff(array_keys($page), ['connections', 'total', 'next']) !== []
            || !is_array($page['connections'] ?? null) || !array_is_list($page['connections'])
            || count($page['connections']) > $limit || !is_int($page['total'] ?? null)
            || $page['total'] < count($page['connections']) || $page['total'] > 1000000000
            || !is_string($page['next'] ?? '') || !$this->reference($page['next'] ?? '', true)) {
            throw new \InvalidArgumentException('INVALID_FRAME');
        }
        $connections = [];
        $ids = [];
        foreach ($page['connections'] as $row) {
            if (!is_array($row) || count($row) !== 3 || array_diff(array_keys($row), ['id', 'user_id', 'transport']) !== []
                || !is_string($row['id'] ?? null) || !$this->reference($row['id']) || isset($ids[$row['id']])
                || !is_int($row['user_id'] ?? null) || $row['user_id'] <= 0
                || !in_array($row['transport'] ?? null, ['tcp', 'udp'], true)) throw new \InvalidArgumentException('INVALID_FRAME');
            $ids[$row['id']] = true;
            $connections[] = ['id' => $row['id'], 'user_id' => (string) $row['user_id'], 'transport' => $row['transport']];
        }
        $next = $page['next'] ?? '';
        if ($next !== '' && ($connections === [] || $next !== $connections[count($connections) - 1]['id'])) throw new \InvalidArgumentException('INVALID_FRAME');
        return ['connections' => $connections, 'total' => $page['total'], 'next' => $next];
    }

    private function reference(string $id, bool $empty = false): bool
    {
        return $empty && $id === '' || (bool) preg_match('/^[a-f0-9]{32}:[1-9][0-9]{0,19}$/D', $id);
    }
}
