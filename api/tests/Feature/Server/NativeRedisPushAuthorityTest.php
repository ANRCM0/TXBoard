<?php
namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\WebSocket\NodeWorker;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Services\MachineRuntimeUpdateService;
use Mockery;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;

final class NativeRedisPushAuthorityTest extends TestCase
{
    private array $sent=[];
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.native_push_boundary'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true],'database.default'=>'native_push_boundary','cache.default'=>'array','setting.cache_store'=>'array']);
        DB::purge('native_push_boundary');$this->assertSame(':memory:',DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh',['--database'=>'native_push_boundary','--force'=>true])->assertExitCode(0);$this->clearRegistry();
        $devices=Mockery::mock(DeviceStateService::class);$devices->shouldReceive('clearAllNodeDevices')->zeroOrMoreTimes()->andReturn([]);$this->app->instance(DeviceStateService::class,$devices);
    }
    protected function tearDown(): void {$this->clearRegistry();RefreshDatabaseState::$migrated=false;parent::tearDown();}
    private function clearRegistry(): void {foreach(NodeRegistry::getConnectedNodeIds()as$id)NodeRegistry::remove($id);foreach(NodeRegistry::getConnectedMachineIds()as$id)NodeRegistry::removeMachine($id);}
    private function machine(string $token): ServerMachine{return ServerMachine::create(['name'=>'synthetic-machine','token'=>$token,'is_active'=>true]);}
    private function node(?int $machineId): Server{return Server::withoutEvents(fn()=>Server::create(['name'=>'synthetic-node','type'=>'vless','host'=>'127.0.0.1','port'=>24443,'server_port'=>24443,'group_ids'=>[],'rate'=>1,'enabled'=>true,'machine_id'=>$machineId,'protocol_settings'=>['tls'=>0,'network'=>'tcp']]));}
    private function socket(ServerMachine $machine,array $ids): TcpConnection
    {
        $conn=Mockery::mock(TcpConnection::class);$conn->txnodeNative=true;$conn->machineId=(int)$machine->id;$conn->machineNodeIds=$ids;$conn->txnodeCredentialHash=hash('sha256',$machine->token);$conn->lastPongAt=time();
        $conn->shouldReceive('send')->zeroOrMoreTimes()->andReturnUsing(function(string $frame)use($machine):bool{$this->sent[]=['machine_id'=>$machine->id,'frame'=>json_decode($frame,true,32,JSON_THROW_ON_ERROR)];return true;});return $conn;
    }
    private function dispatch(array $payload): void {$worker=new NodeWorker('127.0.0.1',8077);(new \ReflectionMethod($worker,'dispatchRedisPush'))->invoke($worker,$payload);}
    public function test_new_group_config_after_reassignment_never_reaches_old_machine(): void
    {
        $a=$this->machine('old-machine-secret');$b=$this->machine('current-machine-secret');$node=$this->node($a->id);$sibling=$this->node($a->id);
        $old=$this->socket($a,[$node->id,$sibling->id]);$current=$this->socket($b,[]);$old->shouldNotReceive('close');$current->shouldNotReceive('close');
        NodeRegistry::addMachine($a->id,$old);NodeRegistry::add($node->id,$old);NodeRegistry::add($sibling->id,$old);NodeRegistry::addMachine($b->id,$current);
        DB::table('tx_server')->where('id',$node->id)->update(['machine_id'=>$b->id]);
        $this->dispatch(['node_id'=>$node->id,'event'=>'sync.users','data'=>['users'=>[['id'=>991,'uuid'=>'PRIVATE-CURRENT-CREDENTIAL']]]]);
        foreach($this->sent as$record){$frame=$record['frame'];if($record['machine_id']===$a->id){$this->assertSame('sync.nodes',$frame['event']);$this->assertStringNotContainsString('PRIVATE-CURRENT-CREDENTIAL',json_encode($frame));}}
        $this->assertSame($current,NodeRegistry::get($node->id));$this->assertSame($old,NodeRegistry::get($sibling->id));
    }
    public function test_user_changes_only_request_signed_refresh_and_retired_delta_is_dropped(): void
    {
        $machine=$this->machine('current-machine-secret');$node=$this->node($machine->id);$conn=$this->socket($machine,[$node->id]);$conn->shouldNotReceive('close');NodeRegistry::addMachine($machine->id,$conn);NodeRegistry::add($node->id,$conn);
        $this->dispatch(['node_id'=>$node->id,'event'=>'sync.users','data'=>['users'=>[['id'=>991,'uuid'=>'DO-NOT-FORWARD']]]]);
        $frame=$this->sent[0]['frame'];$this->assertSame('sync.users',$frame['event']);$this->assertSame(['refresh_required'=>true,'node_id'=>$node->id],$frame['data']);
        $count=count($this->sent);
        foreach(['sync.user.delta','sync.devices','unknown.secret']as$event){$this->dispatch(['node_id'=>$node->id,'event'=>$event,'data'=>['uuid'=>'DO-NOT-FORWARD']]);}
        $this->assertCount($count,$this->sent);
        $this->assertStringNotContainsString('DO-NOT-FORWARD',json_encode($this->sent));
    }
    public function test_rotated_machine_credential_drops_queued_node_payload_and_closes_session(): void
    {
        $machine=$this->machine('old-token');$node=$this->node($machine->id);$conn=$this->socket($machine,[$node->id]);NodeRegistry::addMachine($machine->id,$conn);NodeRegistry::add($node->id,$conn);$conn->shouldReceive('close')->once();
        DB::table('tx_server_machine')->where('id',$machine->id)->update(['token'=>'new-token']);
        $this->dispatch(['node_id'=>$node->id,'event'=>'sync.config','data'=>['config'=>['secret'=>'DO-NOT-FORWARD']]]);$this->assertSame([],$this->sent);
    }
    public function test_bounded_runtime_update_keeps_current_authentication_and_live_request_check(): void
    {
        $machine=$this->machine('current-token');$conn=$this->socket($machine,[]);$conn->shouldReceive('close')->once();NodeRegistry::addMachine($machine->id,$conn);
        $request='mup_01j2r3v4x5y6z7a8b9c0d1e2f3';Cache::put("machine_runtime_update:cooldown:{$machine->id}",$request,45);
        $payload=['machine_id'=>$machine->id,'event'=>MachineRuntimeUpdateService::EVENT,'data'=>['request_id'=>$request,'target'=>'dev']];
        $this->dispatch($payload);$this->assertCount(1,$this->sent);$this->assertSame(MachineRuntimeUpdateService::EVENT,$this->sent[0]['frame']['event']);
        $this->dispatch(['machine_id'=>$machine->id,'event'=>MachineRuntimeUpdateService::EVENT,'data'=>['request_id'=>$request,'target'=>'shell-command']]);$this->assertCount(1,$this->sent);
        DB::table('tx_server_machine')->where('id',$machine->id)->update(['token'=>'rotated']);$this->dispatch($payload);$this->assertCount(1,$this->sent);
    }

}
