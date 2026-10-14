<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\Services\NodeSyncService;
use App\WebSocket\NodeWorker;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Mockery;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;

class NativeMachineRegistryLifecycleTest extends TestCase
{
    private array $cleared = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Independent memory connection: never reset the application's external
        // database, even when another CI suite uses a dedicated MySQL fixture.
        config(['database.connections.native_machine_registry_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'database.default' => 'native_machine_registry_fixture']);
        DB::purge('native_machine_registry_fixture');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--database' => 'native_machine_registry_fixture', '--force' => true])->assertExitCode(0);
        config(['cache.default' => 'array', 'setting.cache_store' => 'array']);
        foreach (NodeRegistry::getConnectedNodeIds() as $id) NodeRegistry::remove($id);
        foreach (NodeRegistry::getConnectedMachineIds() as $id) NodeRegistry::removeMachine($id);
        $devices = Mockery::mock(DeviceStateService::class);
        $devices->shouldReceive('clearAllNodeDevices')->andReturnUsing(function (int $id): array { $this->cleared[] = $id; return []; });
        $this->app->instance(DeviceStateService::class, $devices);
    }

    protected function tearDown(): void
    {
        foreach (NodeRegistry::getConnectedNodeIds() as $id) NodeRegistry::remove($id);
        foreach (NodeRegistry::getConnectedMachineIds() as $id) NodeRegistry::removeMachine($id);
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function socket(?int $machineId, array $nodeIds = []): TcpConnection
    {
        $conn = Mockery::mock(TcpConnection::class);
        $conn->txnodeNative = true;
        $conn->machineId = $machineId;
        $conn->machineNodeIds = $nodeIds;
        $conn->lastPongAt = time();
        $conn->shouldReceive('send')->zeroOrMoreTimes()->andReturn(true);
        return $conn;
    }

    public function test_standalone_successor_is_installed_before_old_close_callback(): void
    {
        $worker = new NodeWorker('127.0.0.1', 8077);
        $old = $this->socket(null); $old->nodeId = 42;
        $new = $this->socket(null); $new->nodeId = 42;
        $old->shouldReceive('close')->once()->andReturnUsing(function () use ($worker, $old, $new): void {
            $this->assertSame($new, NodeRegistry::get(42));
            $worker->onClose($old);
        });
        NodeRegistry::add(42, $old); NodeSyncService::markNodeOnline(42);
        NodeRegistry::add(42, $new);
        $this->assertSame($new, NodeRegistry::get(42));
        $this->assertTrue(NodeSyncService::isNodeOnline(42));
        $this->assertSame([], $this->cleared);
    }

    public function test_moving_one_member_detaches_it_without_closing_machine_or_sibling(): void
    {
        $old = $this->socket(7, [41, 42]); $old->shouldNotReceive('close');
        $new = $this->socket(8, [42]); $new->shouldNotReceive('close');
        NodeRegistry::addMachine(7, $old); NodeRegistry::addMachine(8, $new);
        NodeRegistry::add(41, $old); NodeRegistry::add(42, $old);
        NodeSyncService::markMachineOnline(7); NodeSyncService::markNodeOnline(41); NodeSyncService::markNodeOnline(42);
        NodeRegistry::add(42, $new); NodeSyncService::markNodeOnline(42);
        $this->assertSame($old, NodeRegistry::getMachine(7));
        $this->assertSame($old, NodeRegistry::get(41));
        $this->assertSame($new, NodeRegistry::get(42));
        $this->assertSame([41], $old->machineNodeIds);
        $this->assertSame([42], $this->cleared);
        $this->assertTrue(NodeSyncService::isNodeOnline(41));
        $this->assertTrue(NodeSyncService::isNodeOnline(42));
        $this->assertTrue(NodeSyncService::isMachineOnline(7));
    }

    public function test_refresh_removal_clears_only_expected_member_and_retains_later_replacement(): void
    {
        $old = $this->socket(7, [41, 42]); $old->shouldNotReceive('close');
        NodeRegistry::addMachine(7, $old); NodeRegistry::add(41, $old); NodeRegistry::add(42, $old);
        NodeSyncService::markNodeOnline(41); NodeSyncService::markNodeOnline(42);
        NodeRegistry::refreshMachineNodes(7, [41]);
        $this->assertNull(NodeRegistry::get(42));
        $this->assertFalse(NodeSyncService::isNodeOnline(42));
        $this->assertTrue(NodeSyncService::isNodeOnline(41));
        $this->assertSame([42], $this->cleared);
        $new = $this->socket(null); $new->nodeId = 42;
        NodeRegistry::add(42, $new); NodeSyncService::markNodeOnline(42);
        // A stale old membership snapshot cannot clear a later socket's facts.
        $old->machineNodeIds = [41, 42];
        NodeRegistry::refreshMachineNodes(7, [41]);
        $this->assertSame($new, NodeRegistry::get(42));
        $this->assertTrue(NodeSyncService::isNodeOnline(42));
        $this->assertSame([42], $this->cleared);
    }

    public function test_machine_successor_keeps_scope_online_after_stale_close(): void
    {
        $worker = new NodeWorker('127.0.0.1', 8077);
        $old = $this->socket(7, [41]); $new = $this->socket(7, [41]);
        $old->shouldReceive('close')->once()->andReturnUsing(function () use ($worker, $old, $new): void {
            $this->assertSame($new, NodeRegistry::getMachine(7));
            $worker->onClose($old);
        });
        NodeRegistry::addMachine(7, $old); NodeRegistry::add(41, $old);
        NodeSyncService::markMachineOnline(7); NodeSyncService::markNodeOnline(41);
        NodeRegistry::addMachine(7, $new); NodeRegistry::add(41, $new); NodeSyncService::markNodeOnline(41);
        $worker->onClose($old);
        $this->assertSame($new, NodeRegistry::getMachine(7));
        $this->assertSame($new, NodeRegistry::get(41));
        $this->assertTrue(NodeSyncService::isMachineOnline(7));
        $this->assertTrue(NodeSyncService::isNodeOnline(41));
    }

    public function test_shared_machine_reconciliation_withdraws_disabled_member_only(): void
    {
        $machine = ServerMachine::create(['name' => 'synthetic', 'token' => 'fixture-machine', 'is_active' => true]);
        $node = fn (string $name): Server => Server::withoutEvents(fn () => Server::create(['name' => $name, 'type' => 'vless', 'host' => '127.0.0.1', 'port' => 24443,
            'server_port' => 24443, 'group_ids' => [], 'rate' => 1, 'enabled' => true, 'machine_id' => $machine->id, 'protocol_settings' => ['tls' => 0, 'network' => 'tcp']]));
        $a = $node('a'); $b = $node('b');
        $conn = $this->socket($machine->id, [$a->id, $b->id]); $conn->txnodeCredentialHash = hash('sha256', $machine->token); $conn->shouldNotReceive('close');
        NodeRegistry::addMachine($machine->id, $conn); NodeRegistry::add($a->id, $conn); NodeRegistry::add($b->id, $conn);
        NodeSyncService::markMachineOnline($machine->id); NodeSyncService::markNodeOnline($a->id); NodeSyncService::markNodeOnline($b->id);
        Server::withoutEvents(fn () => $a->update(['enabled' => false]));
        $worker = new NodeWorker('127.0.0.1', 8077);
        (new \ReflectionMethod($worker, 'reconcileConnections'))->invoke($worker);
        $this->assertNull(NodeRegistry::get($a->id));
        $this->assertSame($conn, NodeRegistry::get($b->id));
        $this->assertSame($conn, NodeRegistry::getMachine($machine->id));
        $this->assertSame([$a->id], $this->cleared);
        $this->assertFalse(NodeSyncService::isNodeOnline($a->id));
        $this->assertTrue(NodeSyncService::isNodeOnline($b->id));
        $this->assertTrue(NodeSyncService::isMachineOnline($machine->id));
    }

    public function test_generic_registry_connections_do_not_perform_native_cache_or_device_cleanup(): void
    {
        $old = Mockery::mock(TcpConnection::class); $old->machineId = 7; $old->machineNodeIds = [41, 42]; $old->shouldNotReceive('close');
        $new = Mockery::mock(TcpConnection::class);
        NodeRegistry::addMachine(7, $old); NodeRegistry::add(41, $old); NodeRegistry::add(42, $old);
        NodeRegistry::add(42, $new);
        NodeRegistry::refreshMachineNodes(7, []);
        $this->assertSame([], $this->cleared);
    }
    public function test_current_close_clears_each_owned_node_and_machine_once(): void
    {
        $conn = $this->socket(7, [41, 42]);
        NodeRegistry::addMachine(7, $conn); NodeRegistry::add(41, $conn); NodeRegistry::add(42, $conn);
        NodeSyncService::markMachineOnline(7); NodeSyncService::markNodeOnline(41); NodeSyncService::markNodeOnline(42);
        $worker = new NodeWorker('127.0.0.1', 8077);
        $worker->onClose($conn); $worker->onClose($conn);
        $this->assertNull(NodeRegistry::getMachine(7));
        $this->assertNull(NodeRegistry::get(41)); $this->assertNull(NodeRegistry::get(42));
        $this->assertFalse(NodeSyncService::isMachineOnline(7));
        $this->assertFalse(NodeSyncService::isNodeOnline(41)); $this->assertFalse(NodeSyncService::isNodeOnline(42));
        $this->assertSame([41, 42], $this->cleared);
    }

    public function test_forced_machine_sync_uses_current_database_membership_and_full_syncs_only_new_node(): void
    {
        $machine = ServerMachine::create(['name' => 'synthetic', 'token' => 'fixture-machine', 'is_active' => true]);
        $make = fn (string $name, bool $enabled): Server => Server::withoutEvents(fn () => Server::create(['name' => $name, 'type' => 'vless', 'host' => '127.0.0.1', 'port' => 24443,
            'server_port' => 24443, 'group_ids' => [], 'rate' => 1, 'enabled' => $enabled, 'machine_id' => $machine->id, 'protocol_settings' => ['tls' => 0, 'network' => 'tcp']]));
        $old = $make('old-disabled', false); $sibling = $make('sibling', true); $new = $make('new-enabled', true);
        $sent = [];
        $conn = Mockery::mock(TcpConnection::class);
        $conn->txnodeNative = true; $conn->machineId = $machine->id; $conn->machineNodeIds = [$old->id, $sibling->id];
        $conn->txnodeCredentialHash = hash('sha256', $machine->token); $conn->lastPongAt = time();
        $conn->shouldNotReceive('close');
        $conn->shouldReceive('send')->andReturnUsing(function ($frame) use (&$sent): bool { $sent[] = json_decode((string) $frame, true, 32, JSON_THROW_ON_ERROR); return true; });
        NodeRegistry::addMachine($machine->id, $conn); NodeRegistry::add($old->id, $conn); NodeRegistry::add($sibling->id, $conn);
        NodeSyncService::markNodeOnline($old->id); NodeSyncService::markNodeOnline($sibling->id);
        $worker = new NodeWorker('127.0.0.1', 8077);
        (new \ReflectionMethod($worker, 'synchronizeMachine'))->invoke($worker, $machine->id, true);
        $this->assertSame('sync.nodes', $sent[0]['event']);
        $this->assertSame([$sibling->id, $new->id], array_column($sent[0]['data']['nodes'], 'id'));
        $this->assertSame(['sync.nodes', 'sync.config', 'sync.users'], array_column($sent, 'event'));
        $this->assertSame($new->id, $sent[1]['data']['node_id']);
        $this->assertSame($new->id, $sent[2]['data']['node_id']);
        $this->assertNull(NodeRegistry::get($old->id)); $this->assertSame($conn, NodeRegistry::get($new->id));
        $this->assertFalse(NodeSyncService::isNodeOnline($old->id)); $this->assertTrue(NodeSyncService::isNodeOnline($new->id));
        $this->assertSame([$old->id], $this->cleared);
    }

}
