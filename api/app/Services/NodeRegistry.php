<?php

namespace App\Services;

use Workerman\Connection\TcpConnection;
use Illuminate\Support\Facades\Log;

/**
 * In-memory registry for active WebSocket node connections.
 * Runs inside the Workerman process.
 */
class NodeRegistry
{
    /** @var array<int, TcpConnection> nodeId → connection */
    private static array $connections = [];

    /** @var array<int, TcpConnection> machineId → connection */
    private static array $machineConnections = [];

    public static function add(int $nodeId, TcpConnection $conn): void
    {
        if (!empty($conn->machineId) && (self::getMachine((int) $conn->machineId) !== $conn
            || !in_array($nodeId, array_map('intval', $conn->machineNodeIds ?? []), true))) return;
        $old = self::$connections[$nodeId] ?? null;
        if ($old === $conn) return;
        // Install first, so mandatory registry transition survives cache faults.
        self::$connections[$nodeId] = $conn;
        if ($old === null) return;
        if (!empty($old->machineId)) {
            self::forgetSocketMember($nodeId, $old);
            if ((int) $old->machineId !== (int) ($conn->machineId ?? 0)) self::withdrawNativeFacts($nodeId, $old);
        } else {
            $old->close();
        }
    }

    public static function addMachine(int $machineId, TcpConnection $conn): void
    {
        $old = self::$machineConnections[$machineId] ?? null;
        if ($old === $conn) return;
        $newIds = array_values(array_unique(array_filter(array_map('intval', $conn->machineNodeIds ?? []), static fn (int $id): bool => $id > 0)));
        $oldIds = $old?->machineNodeIds ?? [];
        $displaced = [];
        // The complete successor registry is installed before any cleanup/close.
        self::$machineConnections[$machineId] = $conn;
        $conn->machineNodeIds = $newIds;
        foreach ($newIds as $nodeId) {
            $previous = self::$connections[$nodeId] ?? null;
            if ($previous !== null && $previous !== $conn) $displaced[$nodeId] = $previous;
            self::$connections[$nodeId] = $conn;
        }
        try {
            foreach ($displaced as $nodeId => $previous) {
                if (!empty($previous->machineId)) {
                    self::forgetSocketMember($nodeId, $previous);
                    // Same-machine replacement keeps current node device facts.
                    if ((int) $previous->machineId !== $machineId) self::withdrawNativeFacts($nodeId, $previous);
                } else {
                    $previous->close();
                }
            }
            if ($old !== null) {
                foreach (array_diff($oldIds, $newIds) as $nodeId) self::detachNode((int) $nodeId, $old);
                $old->machineNodeIds = [];
            }
        } finally {
            if ($old !== null) $old->close();
        }
    }

    private static function forgetSocketMember(int $nodeId, TcpConnection $conn): void
    {
        $conn->machineNodeIds = array_values(array_filter($conn->machineNodeIds ?? [], static fn ($id): bool => (int) $id !== $nodeId));
    }

    private static function withdrawNativeFacts(int $nodeId, TcpConnection $expected): void
    {
        if (empty($expected->txnodeNative)) return;
        try { NodeSyncService::markNodeOffline($nodeId); }
        catch (\Throwable $e) { Log::warning('[WS] Native node cleanup failed', ['node_id' => $nodeId, 'failure_code' => 'NATIVE_NODE_ONLINE_CLEANUP_FAILED']); }
        try { app(DeviceStateService::class)->clearAllNodeDevices($nodeId); }
        catch (\Throwable $e) { Log::warning('[WS] Native node cleanup failed', ['node_id' => $nodeId, 'failure_code' => 'NATIVE_NODE_DEVICE_CLEANUP_FAILED']); }
    }

    /** Withdraw one expected mapping and its native facts, never a successor's. */
    public static function detachNode(int $nodeId, TcpConnection $expected): bool
    {
        // Membership metadata is local to the old socket even when its node
        // mapping was already replaced; stale close/refresh may still see it.
        if (!empty($expected->machineId)) {
            $expected->machineNodeIds = array_values(array_filter($expected->machineNodeIds ?? [],
                static fn ($id): bool => (int) $id !== $nodeId));
        }
        if ((self::$connections[$nodeId] ?? null) !== $expected) return false;
        unset(self::$connections[$nodeId]);
        // The generic registry remains usable without cache/device providers.
        // Only authenticated native session withdrawal owns these side effects.
        self::withdrawNativeFacts($nodeId, $expected);
        return true;
    }

    /**
     * Remove a node mapping only if it still points to the given connection.
     * Passing null removes unconditionally; live socket callers must pass the expected connection.
     */
    public static function remove(int $nodeId, ?TcpConnection $conn = null): void
    {
        if ($conn !== null && isset(self::$connections[$nodeId]) && self::$connections[$nodeId] !== $conn) {
            return; // already replaced by a newer connection
        }
        unset(self::$connections[$nodeId]);
    }

    public static function removeMachine(int $machineId, ?TcpConnection $conn = null): void
    {
        if ($conn !== null && isset(self::$machineConnections[$machineId]) && self::$machineConnections[$machineId] !== $conn) {
            return;
        }
        unset(self::$machineConnections[$machineId]);
    }

    public static function get(int $nodeId): ?TcpConnection
    {
        return self::$connections[$nodeId] ?? null;
    }

    public static function getMachine(int $machineId): ?TcpConnection
    {
        return self::$machineConnections[$machineId] ?? null;
    }

    /**
     * Send a versioned native frame to a specific node.
     */
    public static function send(int $nodeId, string $event, array $data): bool
    {
        $conn = self::get($nodeId);
        if (!$conn) {
            return false;
        }

        // Machine-mode connections multiplex multiple node IDs through the same
        // socket, so node-scoped events must carry node_id for the client mux.
        if (!empty($conn->machineNodeIds) && $event !== 'sync.nodes' && !array_key_exists('node_id', $data)) {
            $data['node_id'] = $nodeId;
        }

        $payload = \App\WebSocket\NativeNodeFrame::encode($event, $data);

        $conn->send($payload);
        return true;
    }

    /**
     * Update in-memory registry when a machine's node set changes.
     * Called from the WS process when a sync.nodes event is dispatched.
     */
    public static function refreshMachineNodes(int $machineId, array $newNodeIds): void
    {
        $conn = self::getMachine($machineId);
        if (!$conn) {
            return;
        }

        $oldNodeIds = $conn->machineNodeIds ?? [];

        $newNodeIds = array_values(array_unique(array_map('intval', $newNodeIds)));
        foreach (array_diff($oldNodeIds, $newNodeIds) as $removedId) self::detachNode((int) $removedId, $conn);
        $conn->machineNodeIds = $newNodeIds;
        foreach ($newNodeIds as $nodeId) {
            // add() detaches shared siblings safely and closes only a replaced
            // standalone connection, after the successor mapping is installed.
            self::add($nodeId, $conn);
            if (!empty($conn->txnodeNative)) NodeSyncService::markNodeOnline($nodeId);
        }

        $conn->machineNodeIds = $newNodeIds;
    }

    public static function sendMachine(int $machineId, string $event, array $data): bool
    {
        $conn = self::getMachine($machineId);
        if (!$conn) {
            return false;
        }

        $payload = \App\WebSocket\NativeNodeFrame::encode($event, $data);

        $conn->send($payload);
        return true;
    }

    /**
     * Get the connection for a node by ID, checking if it's still alive.
     */
    public static function isOnline(int $nodeId): bool
    {
        $conn = self::get($nodeId);
        return $conn !== null && $conn->getStatus() === TcpConnection::STATUS_ESTABLISHED;
    }

    /**
     * Get all connected node IDs.
     * @return int[]
     */
    public static function getConnectedNodeIds(): array
    {
        return array_keys(self::$connections);
    }

    public static function count(): int
    {
        return count(self::$connections);
    }

    /**
     * @return int[]
     */
    public static function getConnectedMachineIds(): array
    {
        return array_keys(self::$machineConnections);
    }

    public static function machineCount(): int
    {
        return count(self::$machineConnections);
    }
}

