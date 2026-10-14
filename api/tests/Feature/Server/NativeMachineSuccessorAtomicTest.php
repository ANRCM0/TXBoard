<?php
namespace Tests\Feature\Server;

use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\Services\NodeSyncService;
use App\WebSocket\NodeWorker;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;

final class NativeMachineSuccessorAtomicTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        $this->clearRegistry();
    }
    protected function tearDown(): void { $this->clearRegistry(); parent::tearDown(); }
    private function clearRegistry(): void
    {
        foreach (NodeRegistry::getConnectedNodeIds() as $id) NodeRegistry::remove($id);
        foreach (NodeRegistry::getConnectedMachineIds() as $id) NodeRegistry::removeMachine($id);
    }
    private function socket(int $machine, array $ids): TcpConnection
    {
        $conn=Mockery::mock(TcpConnection::class);$conn->txnodeNative=true;$conn->machineId=$machine;$conn->machineNodeIds=$ids;
        return $conn;
    }
    public function test_current_membership_is_installed_before_old_close_and_intersection_devices_survive(): void
    {
        $worker=new NodeWorker('127.0.0.1',8077);$old=$this->socket(7,[41,42]);$next=$this->socket(7,[41,43]);
        $devices=Mockery::mock(DeviceStateService::class);$devices->shouldReceive('clearAllNodeDevices')->once()->with(42)->andReturn([]);$this->app->instance(DeviceStateService::class,$devices);
        NodeRegistry::addMachine(7,$old);NodeRegistry::add(41,$old);NodeRegistry::add(42,$old);NodeSyncService::markNodeOnline(41);NodeSyncService::markNodeOnline(42);
        $old->shouldReceive('close')->once()->andReturnUsing(function()use($worker,$old,$next):void {
            $this->assertSame($next,NodeRegistry::getMachine(7));
            $this->assertSame($next,NodeRegistry::get(41));
            $this->assertSame($next,NodeRegistry::get(43));
            $this->assertTrue(NodeSyncService::isNodeOnline(41));
            $this->assertNull(NodeRegistry::get(42));
            $worker->onClose($old);
        });
        NodeRegistry::addMachine(7,$next);
        $this->assertSame($next,NodeRegistry::get(41));$this->assertSame($next,NodeRegistry::get(43));$this->assertFalse(NodeSyncService::isNodeOnline(42));
    }
    public function test_device_cleanup_failure_does_not_skip_close_or_lose_successor_mapping(): void
    {
        $old=$this->socket(7,[41,42]);$next=$this->socket(7,[41]);$devices=Mockery::mock(DeviceStateService::class);
        $devices->shouldReceive('clearAllNodeDevices')->once()->with(42)->andThrow(new \RuntimeException('private-cache-detail'));
        $this->app->instance(DeviceStateService::class,$devices);
        Log::shouldReceive('warning')->once()->with('[WS] Native node cleanup failed',['node_id'=>42,'failure_code'=>'NATIVE_NODE_DEVICE_CLEANUP_FAILED']);
        NodeRegistry::addMachine(7,$old);NodeRegistry::add(41,$old);NodeRegistry::add(42,$old);NodeSyncService::markNodeOnline(41);
        $old->shouldReceive('close')->once()->andReturnUsing(function()use($next):void{$this->assertSame($next,NodeRegistry::get(41));$this->assertNull(NodeRegistry::get(42));});
        NodeRegistry::addMachine(7,$next);
        $this->assertSame($next,NodeRegistry::getMachine(7));$this->assertSame($next,NodeRegistry::get(41));
    }
    public function test_old_machine_member_cannot_overwrite_current_owner_after_transition(): void
    {
        $old=$this->socket(7,[41,42]);$current=$this->socket(8,[42]);$old->shouldNotReceive('close');$current->shouldNotReceive('close');
        $devices=Mockery::mock(DeviceStateService::class);$devices->shouldReceive('clearAllNodeDevices')->once()->with(42)->andReturn([]);$this->app->instance(DeviceStateService::class,$devices);
        NodeRegistry::addMachine(7,$old);NodeRegistry::addMachine(8,$current);
        NodeRegistry::add(42,$old);
        $this->assertSame($current,NodeRegistry::get(42));$this->assertSame([41],$old->machineNodeIds);
    }

}
