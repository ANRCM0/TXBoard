<?php

namespace Tests\Feature\AuthorityMigration;

use App\Domains\AuthorityMigration\AuthorityFenceException;
use App\Domains\AuthorityMigration\AuthorityInventory;
use App\Domains\AuthorityMigration\AuthorityInventoryUpgrade;
use App\Domains\AuthorityMigration\AuthorityWriterFence;
use App\Jobs\TrafficBatchJob;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorityInventoryExpandTest extends TestCase
{
    private User $admin;
    protected function setUp():void
    {
        parent::setUp();$name=$this->connection();
        config(['database.default'=>$name,'database.connections.'.$name=>$this->mysql()?config('database.connections.mysql'):
            array_replace(config('database.connections.sqlite'),['database'=>':memory:'])]);DB::purge($name);
        if($this->mysql())$this->clearMysql();$this->assertSame(0,Artisan::call('migrate',['--force'=>true]));Bus::fake();HookManager::reset();
        config(['authority_writer_fence'=>['enabled'=>true,'worker_epoch'=>'1','worker_role'=>'legacy'],
            'core_shadow.isolated_copy'=>true,'legacy_capture_shadow.enabled'=>false,'legacy_admin_creation_shadow.enabled'=>false,
            'legacy_admin_deletion_shadow.enabled'=>false,'legacy_observer_capture.enabled'=>false,
            'legacy_bulk_ban_capture.enabled'=>false,'legacy_plan_propagation_capture.enabled'=>false]);
        config(['authority_writer_fence.enabled'=>false]);
        admin_setting(['secure_path'=>'inventory_fixture','try_out_plan_id'=>0]);
        config(['authority_writer_fence.enabled'=>true]);
        $this->admin=$this->user(['is_admin'=>true]);Sanctum::actingAs($this->admin);
        Redis::shouldReceive('sadd')->zeroOrMoreTimes()->andReturn(1);
    }
    protected function tearDown():void
    {HookManager::reset();if($this->mysql())$this->clearMysql();DB::purge($this->connection());parent::tearDown();}

    public function test_explicit_expand_changes_same_singleton_and_exact_replay_retains_immutable_receipt():void
    {
        $initial=$this->fence()->initialize();$one=$this->fence()->expand('inventory-fixture','1');$two=$this->fence()->expand('inventory-fixture','1');
        $this->assertFalse($one['replayed']);$this->assertTrue($two['replayed']);$this->assertSame($one['upgrade_receipt'],$two['upgrade_receipt']);
        $this->assertSame('2',$one['authority_epoch']);$this->assertSame('open',$one['phase']);$this->assertSame(AuthorityInventory::SCOPE,$one['scope']);
        $this->assertSame(AuthorityInventory::VERSION,$one['version']);$this->assertSame(AuthorityInventory::WRITERS,$one['covered_writers']);
        $this->assertSame($initial['state_hash'],$one['upgrade_receipt']['prior_state_hash']);$this->assertSame(1,DB::table('tx_authority_writer_fence')->count());
        $this->refused(fn()=>$this->fence()->expand('other','1'),'AUTHORITY_INVENTORY_REQUEST_CONFLICT');
        $this->refused(fn()=>$this->fence()->expand('inventory-fixture','2'),'AUTHORITY_INVENTORY_REQUEST_CONFLICT');
        foreach(['global_writer_coverage_complete','global_drain_complete','target_authority_active','migration_gate_open']as$key)$this->assertFalse($one[$key]);
    }
    public function test_expanded_prepare_uses_original_expected_epoch_and_readonly_recovery_ignores_worker_declaration():void
    {
        $this->expand();$prepared=$this->fence()->prepare('expanded-prepare','2');$this->assertSame('3',$prepared['authority_epoch']);
        $this->assertSame('2',$prepared['receipt']['expected_epoch']);$this->assertSame('3',$prepared['receipt']['prepared_epoch']);
        $this->assertSame(AuthorityInventory::VERSION,$prepared['receipt']['version']);
        $replay=$this->fence()->prepare('expanded-prepare','2');$this->assertTrue($replay['replayed']);$this->assertSame($prepared['receipt'],$replay['receipt']);
        config(['authority_writer_fence'=>['enabled'=>true,'worker_epoch'=>'1','worker_role'=>'legacy']]);
        $this->assertSame($prepared['receipt'],$this->fence()->recover('expanded-prepare','2')['receipt']);
        $this->assertSame('committed_expand',$this->fence()->recoverExpand('inventory-fixture','1')['recovery_status']);
        $this->assertSame('3',$this->fence()->status()['authority_epoch']);
    }
    public function test_actual_v1_prepared_history_before_new_column_keeps_original_hashes_and_cannot_expand():void
    {
        Schema::table('tx_authority_writer_fence',fn($t)=>$t->dropColumn('inventory_hash'));
        $prior=['id'=>'1','scope'=>'admin-user-editor/v1','version'=>'authority-writer-fence/v1','authority'=>'legacy','phase'=>'open',
            'authority_epoch'=>'1','prepare_key'=>null,'row_version'=>'1','updated_at'=>'1700000000'];
        $prepared=array_replace($prior,['phase'=>'prepared','authority_epoch'=>'2','prepare_key'=>'historical-prepare','row_version'=>'2','updated_at'=>'1700000001']);
        $state=$prepared+['content_hash'=>$this->hash($prepared)];
        $receipt=['prepare_key'=>'historical-prepare','scope'=>'admin-user-editor/v1','version'=>'authority-writer-fence/v1',
            'expected_epoch'=>'1','prepared_epoch'=>'2','request_hash'=>$this->hash(['scope'=>'admin-user-editor/v1','version'=>'authority-writer-fence/v1',
                'operation'=>'prepare','prepare_key'=>'historical-prepare','expected_epoch'=>'1']),
            'prior_state_hash'=>$this->hash($prior),'prepared_state_hash'=>$state['content_hash'],'captured_at'=>'1700000001'];
        $receipt['content_hash']=$this->hash($receipt);DB::table('tx_authority_writer_fence')->insert($state);DB::table('tx_authority_prepare_receipt')->insert($receipt);
        $before=$this->history();$migration=require database_path('migrations/2026_10_14_000002_expand_authority_writer_inventory.php');$migration->up();
        $this->newWorker();$recover=$this->fence()->recover('historical-prepare','1');$this->assertSame($receipt,$recover['receipt']);
        $this->assertSame($state['content_hash'],$recover['state_hash']);
        $this->refused(fn()=>$this->fence()->expand('must-not-reopen','1'),'AUTHORITY_INVENTORY_EXPAND_REFUSED');
        $after=$this->history();$this->assertSame($before['prepare'],$after['prepare']);
        $row=(array)DB::table('tx_authority_writer_fence')->first();$this->assertNull($row['inventory_hash']);
        unset($row['inventory_hash']);$this->assertSame($before['state'],json_encode([$row]));
    }
    public function test_wrong_scope_version_epoch_and_fixed_inventory_refuse_before_native_write():void
    {
        $this->fence()->initialize();$this->fence()->expand('inventory-fixture','1');$target=$this->user();$old=$target->token;
        $this->rotate($target->id)->assertStatus(409);$this->newWorker('1');$this->rotate($target->id)->assertStatus(409);
        $this->newWorker();config(['authority_writer_fence.worker_version'=>AuthorityWriterFence::VERSION]);
        $this->refused(fn()=>$this->fence()->status(),'AUTHORITY_FENCE_SCOPE_INVALID');
        $this->newWorker();DB::table('tx_authority_writer_fence')->update(['inventory_hash'=>str_repeat('f',64)]);
        $this->rotate($target->id)->assertStatus(409);$this->assertSame($old,$target->fresh()->token);
    }
    public function test_native_five_new_transaction_owners_admit_before_domain_locks_and_keep_original_behavior():void
    {
        $this->expand();$rotate=$this->user();$ban=$this->user();$delete=$this->user();$planned=$this->user();$plan=$this->plan();
        User::withoutEvents(fn()=>$planned->update(['plan_id'=>$plan->id,'group_id'=>1,'transfer_enable'=>1]));
        $this->assertAdmissionFirst(fn()=>$this->rotate($rotate->id)->assertOk(),'tx_user');
        $this->assertAdmissionFirst(fn()=>$this->postJson($this->users().'/ban',['scope'=>'selected','user_ids'=>[$ban->id]])->assertOk(),'tx_user');
        $this->assertTrue((bool)$ban->fresh()->banned);
        $this->assertAdmissionFirst(fn()=>$this->postJson($this->users().'/'.$delete->id.'/delete')->assertOk(),'tx_user');$this->assertNull($delete->fresh());
        $this->assertAdmissionFirst(fn()=>$this->postJson('/txapi/admin/inventory_fixture/plans',['id'=>$plan->id,'name'=>'Expanded Plan',
            'transfer_enable'=>4,'force_update'=>true])->assertOk(),'tx_plan');
        $this->assertSame(4294967296,(int)$planned->fresh()->transfer_enable);
        $server=$this->server();$job=$this->job($server,$rotate,'native-expanded-batch');
        $this->assertAdmissionFirst(fn()=>$job->handle(),'tx_server');$job->handle();
        $this->assertSame(10,(int)$rotate->fresh()->u);$this->assertSame(20,(int)$rotate->fresh()->d);
        $this->assertSame(1,DB::table('tx_traffic_batch')->count());
    }
    public function test_prepared_epoch3_blocks_all_five_native_owners_without_partial_mutation_or_dedupe_rows():void
    {
        $this->expand();$one=$this->user();$two=$this->user();$three=$this->user();$plan=$this->plan();$server=$this->server();
        $before=$this->business();$this->fence()->prepare('stop-expanded','2');$this->newWorker('3');
        $this->rotate($one->id)->assertStatus(409);
        $this->postJson($this->users().'/ban',['scope'=>'selected','user_ids'=>[$two->id]])->assertStatus(409);
        $this->postJson($this->users().'/'.$three->id.'/delete')->assertStatus(409);
        $this->postJson('/txapi/admin/inventory_fixture/plans',['id'=>$plan->id,'name'=>'Blocked','transfer_enable'=>7,'force_update'=>true])->assertStatus(409);
        $this->refused(fn()=>$this->job($server,$one,'blocked-batch')->handle(),'AUTHORITY_FENCE_PREPARED');
        $this->assertSame($before,$this->business());
    }
    public function test_default_off_missing_new_schema_keeps_all_original_native_transaction_owners():void
    {
        config(['authority_writer_fence'=>['enabled'=>false,'worker_epoch'=>'bad','worker_role'=>'bad'],'core_shadow.isolated_copy'=>false]);
        $this->app['env']='production';Schema::drop('tx_authority_inventory_upgrade');Schema::drop('tx_authority_prepare_receipt');Schema::drop('tx_authority_writer_fence');
        $one=$this->user();$two=$this->user();$three=$this->user();$plan=$this->plan();$server=$this->server();
        DB::enableQueryLog();DB::flushQueryLog();$this->rotate($one->id)->assertOk();
        $this->postJson($this->users().'/ban',['scope'=>'selected','user_ids'=>[$two->id]])->assertOk();
        $this->postJson($this->users().'/'.$three->id.'/delete')->assertOk();
        $this->postJson('/txapi/admin/inventory_fixture/plans',['id'=>$plan->id,'name'=>'Original','transfer_enable'=>2,'force_update'=>true])->assertOk();
        $this->job($server,$one,'default-batch')->handle();$queries=DB::getQueryLog();DB::disableQueryLog();
        foreach($queries as$query)$this->assertStringNotContainsString('tx_authority_',$query['query']);
    }
    public function test_expansion_receipt_insert_failure_rolls_back_same_row_and_readonly_recovery_is_inconclusive():void
    {
        $this->fence()->initialize();$before=$this->history();
        DB::listen(function($query):void{if(preg_match('/^insert.*tx_authority_inventory_upgrade/i',$query->sql))throw new \RuntimeException('synthetic-secret-failure');});
        $this->refused(fn()=>$this->fence()->expand('rollback-expand','1'),'AUTHORITY_FENCE_STORAGE_OR_EVIDENCE_FAILED');
        $this->assertSame($before,$this->history());$this->assertSame('inconclusive_no_committed_expand',$this->fence()->recoverExpand('rollback-expand','1')['recovery_status']);
    }
    public function test_upgrade_receipt_missing_corrupt_extra_rows_and_changed_context_never_silently_pass():void
    {
        $this->expand();$row=(array)DB::table('tx_authority_inventory_upgrade')->first();
        DB::table('tx_authority_inventory_upgrade')->update(['content_hash'=>str_repeat('a',64)]);
        $this->refused(fn()=>$this->fence()->status(),'AUTHORITY_INVENTORY_EVIDENCE_INVALID');DB::table('tx_authority_inventory_upgrade')->update($row);
        $extra=$row;$extra['upgrade_key']='orphan-upgrade';DB::table('tx_authority_inventory_upgrade')->insert($extra);
        $this->refused(fn()=>$this->fence()->expand('inventory-fixture','1'),'AUTHORITY_INVENTORY_EVIDENCE_INVALID');
        DB::table('tx_authority_inventory_upgrade')->where('upgrade_key','orphan-upgrade')->delete();
        Schema::drop('tx_authority_inventory_upgrade');$this->refused(fn()=>$this->fence()->status(),'AUTHORITY_FENCE_STORAGE_OR_EVIDENCE_FAILED');
    }
    public function test_recovery_selects_closed_stores_once_and_does_not_rewrite_bounded_history():void
    {
        $this->expand();$this->fence()->prepare('bounded-prepare','2');$before=$this->history();
        DB::enableQueryLog();DB::flushQueryLog();$this->fence()->status();$this->fence()->recover('bounded-prepare','2');
        $this->fence()->recoverExpand('inventory-fixture','1');$queries=DB::getQueryLog();DB::disableQueryLog();
        $this->assertCount(24,$queries);$this->assertCount(3,array_filter($queries,fn(array $query):bool=>str_contains($query['query'],'tx_authority_identity_upgrade')));
        $this->assertCount(3,array_filter($queries,fn(array $query):bool=>str_contains($query['query'],'tx_authority_ticket_upgrade')));
        foreach($queries as$query)$this->assertMatchesRegularExpression('/\Aselect\b/i',$query['query']);
        $this->assertSame($before,$this->history());
    }
    public function test_upgrade_schema_replay_immutable_receipt_down_guard_and_invalid_nullable_column_are_checked():void
    {
        $this->expand();$before=$this->history();$migration=require database_path('migrations/2026_10_14_000002_expand_authority_writer_inventory.php');
        $migration->up();$this->assertSame($before,$this->history());$receipt=AuthorityInventoryUpgrade::findOrFail('inventory-fixture');
        $this->refused(fn()=>$receipt->update(['expected_epoch'=>2]),'AUTHORITY_INVENTORY_RECEIPT_IMMUTABLE');
        $this->refused(fn()=>$receipt->delete(),'AUTHORITY_INVENTORY_RECEIPT_IMMUTABLE');
        try{$migration->down();$this->fail('Expected populated down refusal');}catch(\RuntimeException $error){$this->assertSame('AUTHORITY_INVENTORY_POPULATED_ROLLBACK_REFUSED',$error->getMessage());}
    }
    public function test_direct_plan_observer_has_no_late_authority_admission_and_new_flags_require_native_inventory():void
    {
        $this->expand();$plan=$this->plan();
        $plan->reset_traffic_method=Plan::RESET_TRAFFIC_FIRST_DAY_MONTH;
        $this->refused(fn()=>(new \App\Observers\PlanObserver())->updated($plan),'AUTHORITY_PLAN_OBSERVER_OUTER_TRANSACTION_REQUIRED');
        $this->fence()->prepare('plan-prepared','2');$showBefore=(bool)$plan->fresh()->show;
        $this->postJson('/txapi/admin/inventory_fixture/plans/'.$plan->id.'/flags',['show'=>false])->assertStatus(409);
        $this->assertSame($showBefore,(bool)$plan->fresh()->show);
    }
    public function test_cli_new_operations_strict_config_shapes_and_unknown_writer_are_refused():void
    {
        $this->fence()->initialize();
        $this->assertSame(0,Artisan::call('core:authority-fence',['operation'=>'expand','--key'=>'cli-expand','--expected-epoch'=>'1','--isolated-copy'=>true]));
        $this->assertSame('2',json_decode(Artisan::output(),true)['authority_epoch']);
        $this->newWorker();$this->assertSame(0,Artisan::call('core:authority-fence',['operation'=>'recover-expand','--key'=>'cli-expand','--expected-epoch'=>'1','--isolated-copy'=>true]));
        DB::transaction(fn()=>$this->refused(fn()=>$this->fence()->admit('order.fulfill'),'AUTHORITY_FENCE_WRITER_INVALID'));
        config(['authority_writer_fence.extra'=>'unknown']);$this->refused(fn()=>$this->fence()->status(),'AUTHORITY_FENCE_ISOLATED_REQUIRED');
        $this->newWorker();config(['authority_writer_fence.worker_scope'=>null]);$this->refused(fn()=>$this->fence()->status(),'AUTHORITY_FENCE_SCOPE_INVALID');
    }

    public function test_real_mysql_old_admitted_native_update_commits_before_expand():void{$this->ordering(false,'commit');}
    public function test_real_mysql_expanded_native_ban_commit_precedes_prepare_epoch3():void{$this->ordering(true,'commit');}
    public function test_real_mysql_expanded_native_ban_rollback_unblocks_prepare_without_mutation():void{$this->ordering(true,'rollback');}
    public function test_real_mysql_expanded_killed_native_ban_rolls_back_and_unblocks_prepare():void{$this->ordering(true,'kill');}
    public function test_real_mysql_expand_killed_before_commit_is_recoverable_by_exact_retry():void{$this->killControl('expand',false);}
    public function test_real_mysql_expand_commit_before_output_loss_recovers_same_upgrade_receipt():void{$this->killControl('expand',true);}
    public function test_real_mysql_expanded_prepare_killed_before_commit_retains_open_epoch2():void{$this->killControl('prepare',false);}
    public function test_real_mysql_expanded_prepare_commit_before_output_loss_recovers_epoch3():void{$this->killControl('prepare',true);}

    public function test_recomputed_prepared_receipt_cannot_break_its_prior_expanded_state_association():void
    {
        $this->expand();$this->fence()->prepare('rehashed-prepare','2');$row=(array)DB::table('tx_authority_prepare_receipt')->first();
        $row['prior_state_hash']=str_repeat('e',64);$payload=$row;unset($payload['content_hash']);
        foreach(['expected_epoch','prepared_epoch','captured_at']as$key)$payload[$key]=(string)$payload[$key];
        $row['content_hash']=$this->hash($payload);DB::table('tx_authority_prepare_receipt')->update($row);
        $this->refused(fn()=>$this->fence()->recover('rehashed-prepare','2'),'AUTHORITY_FENCE_RECEIPT_INVALID');
    }

    private function ordering(bool $expanded,string $mode):void
    {
        $this->requireMysql();if($expanded)$this->expand();else$this->fence()->initialize();$user=$this->user();$name=$this->connection();DB::disconnect($name);
        [$writer,$socket]=$this->spawn(function($socket)use($user,$expanded):void{
            DB::listen(function($query)use($socket):void{if(preg_match('/^update.*tx_user/i',$query->sql)){
                $this->send($socket,['stage'=>'native_write_pending']);$message=$this->receive($socket);
                if($message['action']==='rollback')throw new \RuntimeException('synthetic-rollback');
            }});
            $response=$expanded?$this->postJson($this->users().'/ban',['scope'=>'selected','user_ids'=>[$user->id]])
                :$this->postJson($this->users().'/'.$user->id.'/update',['remarks'=>'old-admitted']);
            $this->send($socket,['status'=>$response->status()]);
        });$control=null;$controlSocket=null;
        try{
            $this->assertSame('native_write_pending',$this->receive($socket)['stage']);
            [$control,$controlSocket]=$this->spawn(function($socket)use($expanded):void{
                $this->send($socket,['stage'=>'control_attempt']);$result=$expanded?$this->fence()->prepare('ordered-prepare','2'):$this->fence()->expand('ordered-expand','1');
                $this->send($socket,['result'=>$result]);
            });$this->assertSame('control_attempt',$this->receive($controlSocket)['stage']);
            $read=[$controlSocket];$write=null;$except=null;$this->assertSame(0,stream_select($read,$write,$except,0,150000));
            if($mode==='kill'){posix_kill($writer,SIGKILL);pcntl_waitpid($writer,$status);$writer=null;}
            else{$this->send($socket,['action'=>$mode]);$this->assertSame($mode==='commit'?200:500,$this->receive($socket)['status']);pcntl_waitpid($writer,$status);$writer=null;}
            $result=$this->receive($controlSocket);$this->assertArrayHasKey('result',$result);$this->assertSame($expanded?'3':'2',$result['result']['authority_epoch']);
            pcntl_waitpid($control,$status);$control=null;
            if($expanded)$this->assertSame($mode==='commit',(bool)$user->fresh()->banned);else$this->assertSame('old-admitted',$user->fresh()->remarks);
        }finally{foreach([$writer,$control]as$pid)if($pid){posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);}fclose($socket);if(is_resource($controlSocket))fclose($controlSocket);DB::purge($name);}
    }
    private function killControl(string $operation,bool $afterCommit):void
    {
        $this->requireMysql();if($operation==='prepare')$this->expand();else$this->fence()->initialize();$name=$this->connection();DB::disconnect($name);
        [$pid,$socket]=$this->spawn(function($socket)use($operation,$afterCommit):void{
            if(!$afterCommit)DB::listen(function($query)use($socket,$operation):void{
                $table=$operation==='expand'?'tx_authority_inventory_upgrade':'tx_authority_prepare_receipt';
                if(preg_match('/^insert/i',$query->sql)&&str_contains($query->sql,$table)){
                    $this->send($socket,['stage'=>'before_commit']);$this->receive($socket);
                }
            });$result=$operation==='expand'?$this->fence()->expand('killed-control','1'):$this->fence()->prepare('killed-control','2');
            $this->send($socket,['stage'=>'after_commit','hash'=>$result[$operation==='expand'?'upgrade_receipt':'receipt']['content_hash']]);$this->receive($socket);
        });
        try{
            $message=$this->receive($socket);$this->assertSame($afterCommit?'after_commit':'before_commit',$message['stage']);
            posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);$pid=null;
            $recover=$operation==='expand'?$this->fence()->recoverExpand('killed-control','1'):$this->fence()->recover('killed-control','2');
            $this->assertSame(($afterCommit?'committed_':'inconclusive_no_committed_').($operation==='expand'?'expand':'prepare'),$recover['recovery_status']);
            $retry=$operation==='expand'?$this->fence()->expand('killed-control','1'):$this->fence()->prepare('killed-control','2');
            $this->assertSame($afterCommit,$retry['replayed']);if($afterCommit)$this->assertSame($message['hash'],$retry[$operation==='expand'?'upgrade_receipt':'receipt']['content_hash']);
        }finally{if($pid){posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);}fclose($socket);DB::purge($name);}
    }
    private function spawn(callable $task):array
    {
        $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);$this->assertIsArray($pair);$pid=pcntl_fork();$this->assertNotSame(-1,$pid);
        if($pid===0){fclose($pair[0]);DB::purge($this->connection());try{$task($pair[1]);}catch(\Throwable $error){$this->send($pair[1],['child_error'=>get_class($error),'code'=>$error instanceof AuthorityFenceException?$error->getMessage():'fixture_failure']);}fclose($pair[1]);exit(0);}
        fclose($pair[1]);return[$pid,$pair[0]];
    }
    private function send($socket,array $value):void{fwrite($socket,json_encode($value,JSON_THROW_ON_ERROR)."\n");fflush($socket);}
    private function receive($socket):array{stream_set_timeout($socket,12);$line=fgets($socket);$this->assertIsString($line);return json_decode($line,true,flags:JSON_THROW_ON_ERROR);}
    private function requireMysql():void{if(!$this->mysql()||!function_exists('pcntl_fork')||!function_exists('posix_kill'))$this->markTestSkipped('Requires independent MySQL processes and SIGKILL.');}
    private function assertAdmissionFirst(callable $operation,string $table):void
    {
        DB::enableQueryLog();DB::flushQueryLog();$operation();$queries=DB::getQueryLog();DB::disableQueryLog();$authority=null;$domain=null;
        foreach($queries as$i=>$q){if($authority===null&&str_contains($q['query'],'tx_authority_writer_fence'))$authority=$i;
            if($domain===null&&str_contains($q['query'],$table))$domain=$i;}
        $this->assertIsInt($authority);$this->assertIsInt($domain);$this->assertLessThan($domain,$authority);
    }
    private function expand():void{$this->fence()->initialize();$this->fence()->expand('inventory-fixture','1');$this->newWorker();}
    private function newWorker(string $epoch='2'):void{config(['authority_writer_fence'=>['enabled'=>true,'worker_epoch'=>$epoch,'worker_role'=>'legacy','worker_scope'=>AuthorityInventory::SCOPE,'worker_version'=>AuthorityInventory::VERSION]]);}
    private function fence():AuthorityWriterFence{return app(AuthorityWriterFence::class);}
    private function users():string{return'/txapi/admin/inventory_fixture/users';}
    private function rotate(int $id):\Illuminate\Testing\TestResponse{return$this->postJson($this->users().'/'.$id.'/subscription-credentials/rotate');}
    private function user(array $attrs=[]):User{return User::withoutEvents(fn()=>User::create(array_replace(['email'=>bin2hex(random_bytes(6)).'@example.test','password'=>'synthetic-no-login','uuid'=>bin2hex(random_bytes(16)),'token'=>bin2hex(random_bytes(16)),'u'=>0,'d'=>0,'balance'=>0,'commission_balance'=>0],$attrs)));}
    private function plan():Plan{return Plan::create(['name'=>'Inventory Plan','group_id'=>1,'transfer_enable'=>1,'prices'=>['monthly'=>1],'reset_traffic_method'=>1]);}
    private function server():Server{return Server::create(['name'=>'Inventory Node','type'=>Server::TYPE_VMESS,'host'=>'127.0.0.1','port'=>'443','server_port'=>443,'rate'=>1,'group_ids'=>[1],'enabled'=>true]);}
    private function job(Server $server,User $user,string $id):TrafficBatchJob{return new TrafficBatchJob(['id'=>$server->id,'rate'=>1],[$user->id=>[10,20]],'vmess',strtotime(date('Y-m-d')),$id);}
    private function business():array{$out=[];foreach(['tx_user','tx_plan','tx_server','tx_traffic_batch','tx_stat_user','tx_stat_server','personal_access_tokens']as$table)$out[$table]=DB::table($table)->orderBy('id')->get()->toJson();return$out;}
    private function history():array{return['state'=>DB::table('tx_authority_writer_fence')->get()->toJson(),'prepare'=>DB::table('tx_authority_prepare_receipt')->get()->toJson(),'upgrade'=>DB::table('tx_authority_inventory_upgrade')->get()->toJson()];}
    private function hash(array $value):string{ksort($value,SORT_STRING);return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
    private function refused(callable $call,string $code):void{try{$call();$this->fail('Expected refusal');}catch(AuthorityFenceException $e){$this->assertSame($code,$e->getMessage());$this->assertNull($e->getPrevious());}}
    private function mysql():bool{return getenv('CORE_AUTHORITY_EXPAND_TEST_MYSQL')==='1';}
    private function connection():string{return$this->mysql()?'authority_expand_mysql':'authority_expand_sqlite';}
    private function clearMysql():void
    {if(DB::connection()->getDatabaseName()!=='txboard_authority_expand_test')throw new \RuntimeException('Expansion requires its dedicated fixture DB.');Schema::disableForeignKeyConstraints();try{foreach(Schema::getTableListing()as$table)Schema::drop($table);}finally{Schema::enableForeignKeyConstraints();}}
}
