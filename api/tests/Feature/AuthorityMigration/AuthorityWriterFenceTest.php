<?php

namespace Tests\Feature\AuthorityMigration;

use App\Domains\AuthorityMigration\AuthorityFenceException;
use App\Domains\AuthorityMigration\AuthorityPrepareReceipt;
use App\Domains\AuthorityMigration\AuthorityWriterFence;
use App\Models\User;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorityWriterFenceTest extends TestCase
{
    private User $admin;
    protected function setUp():void
    {
        parent::setUp();$name=$this->connection();
        config(['database.default'=>$name,'database.connections.'.$name=>$this->mysql()?config('database.connections.mysql'):
            array_replace(config('database.connections.sqlite'),['database'=>':memory:'])]);DB::purge($name);
        if($this->mysql())$this->clearMysql();$this->assertSame(0,Artisan::call('migrate',['--force'=>true]));Bus::fake();
        config(['authority_writer_fence'=>['enabled'=>true,'worker_epoch'=>'1','worker_role'=>'legacy'],'core_shadow.isolated_copy'=>true]);
        // Fixture settings predate the v1 declaration; they are not v1 writers.
        config(['authority_writer_fence.enabled'=>false]);
        admin_setting(['secure_path'=>'authority_fixture','try_out_plan_id'=>0]);
        config(['authority_writer_fence.enabled'=>true]);
        $this->admin=$this->user(['is_admin'=>true]);Sanctum::actingAs($this->admin);HookManager::reset();
    }
    protected function tearDown():void
    {HookManager::reset();if($this->mysql())$this->clearMysql();DB::purge($this->connection());parent::tearDown();}

    public function test_migration_is_empty_manual_init_is_idempotent_and_native_create_update_use_admission_first():void
    {
        $this->assertSame(0,DB::table('tx_authority_writer_fence')->count());$initial=$this->fence()->initialize();
        $this->assertSame($initial,$this->fence()->initialize());$this->assertSame('1',$initial['authority_epoch']);
        $this->assertFalse($initial['migration_gate_open']);
        DB::enableQueryLog();DB::flushQueryLog();$response=$this->createAccount()->assertCreated();$queries=DB::getQueryLog();DB::disableQueryLog();
        $firstFence=$this->firstQuery($queries,'tx_authority_writer_fence');$firstUser=$this->firstQuery($queries,'tx_user');
        $this->assertLessThan($firstUser,$firstFence);$id=$response->json('data.id');
        DB::enableQueryLog();DB::flushQueryLog();$this->updateAccount($id,['remarks'=>'covered update'])->assertOk();$queries=DB::getQueryLog();DB::disableQueryLog();
        $this->assertLessThan($this->firstQuery($queries,'tx_user'),$this->firstQuery($queries,'tx_authority_writer_fence'));
        $this->assertSame('covered update',User::findOrFail($id)->remarks);
        $this->assertSame(0,DB::table('tx_authority_prepare_receipt')->count());
    }
    public function test_default_off_production_keeps_legacy_writes_without_isolated_fence_validation():void
    {
        config(['authority_writer_fence'=>['enabled'=>false,'worker_epoch'=>'invalid','worker_role'=>'v2'],'core_shadow.isolated_copy'=>false]);
        $this->app['env']='production';Schema::drop('tx_authority_writer_fence');Schema::drop('tx_authority_prepare_receipt');
        DB::enableQueryLog();DB::flushQueryLog();$id=$this->createAccount()->assertCreated()->json('data.id');
        $this->updateAccount($id,['remarks'=>'native default'])->assertOk();$queries=DB::getQueryLog();DB::disableQueryLog();
        foreach($queries as$query){
            if(str_contains($query['query'],'sqlite_master')||str_contains($query['query'],'information_schema'))continue;
            $this->assertStringNotContainsString('tx_authority_',$query['query']);
        }
    }
    public function test_missing_state_wrong_epoch_or_role_and_prepared_native_writes_fail_closed():void
    {
        $user=$this->user();$before=$this->businessState();$this->createAccount()->assertStatus(409);$this->assertSame($before,$this->businessState());
        $this->fence()->initialize();config(['authority_writer_fence.worker_epoch'=>'2']);
        $this->updateAccount($user->id,['remarks'=>'stale'])->assertStatus(409);$this->assertNull($user->fresh()->remarks);
        config(['authority_writer_fence.worker_epoch'=>'1','authority_writer_fence.worker_role'=>'v2']);$this->createAccount()->assertStatus(409);
        config(['authority_writer_fence.worker_role'=>'legacy']);$this->fence()->prepare('prepare-fixture','1');
        config(['authority_writer_fence.worker_epoch'=>'2']);$this->createAccount()->assertStatus(409);
        $this->updateAccount($user->id,['remarks'=>'prepared'])->assertStatus(409);
        $this->assertSame($before,$this->businessState());
    }
    public function test_prepare_duplicate_recovery_and_original_native_failures_never_reopen_or_rewrite_receipt():void
    {
        $this->fence()->initialize();$user=$this->user();
        HookManager::register('admin.user.update.before',fn()=>throw new \RuntimeException('synthetic-native-failure'));
        $this->updateAccount($user->id,['remarks'=>'rollback'])->assertStatus(500);$this->assertNull($user->fresh()->remarks);HookManager::reset();
        $one=$this->fence()->prepare('same-key','1');$two=$this->fence()->prepare('same-key','1');
        $this->assertFalse($one['replayed']);$this->assertTrue($two['replayed']);$this->assertSame($one['receipt'],$two['receipt']);
        $this->assertSame('2',$one['authority_epoch']);$this->assertSame('prepared',$this->fence()->initialize()['phase']);
        $this->assertSame($one['receipt'],$this->fence()->recover('same-key','1')['receipt']);
        $this->refused(fn()=>$this->fence()->prepare('same-key','2'),'AUTHORITY_FENCE_REQUEST_CONFLICT');
        $this->refused(fn()=>$this->fence()->prepare('another-key','1'),'AUTHORITY_FENCE_PREPARED');
        $this->refused(fn()=>$this->fence()->recover('another-key','1'),'AUTHORITY_FENCE_REQUEST_CONFLICT');
        $this->assertSame(1,DB::table('tx_authority_prepare_receipt')->count());
    }
    public function test_prepare_storage_failure_rolls_back_epoch_phase_and_receipt_and_recovery_is_inconclusive():void
    {
        $this->fence()->initialize();$before=DB::table('tx_authority_writer_fence')->get()->toJson();
        DB::listen(function($query):void{if(preg_match('/^insert.*tx_authority_prepare_receipt/i',$query->sql))throw new \RuntimeException('synthetic-storage-secret');});
        $this->refused(fn()=>$this->fence()->prepare('rollback-key','1'),'AUTHORITY_FENCE_STORAGE_OR_EVIDENCE_FAILED');
        $this->assertSame($before,DB::table('tx_authority_writer_fence')->get()->toJson());$this->assertSame(0,DB::table('tx_authority_prepare_receipt')->count());
        $this->assertSame('inconclusive_no_committed_prepare',$this->fence()->recover('rollback-key','1')['recovery_status']);
    }
    public function test_status_recover_are_bounded_select_only_and_do_not_certify_global_coverage():void
    {
        $this->fence()->initialize();$this->fence()->prepare('read-only','1');$before=$this->fenceState();
        DB::enableQueryLog();DB::flushQueryLog();$status=$this->fence()->status();$recover=$this->fence()->recover('read-only','1');$queries=DB::getQueryLog();DB::disableQueryLog();
        foreach($queries as$query)$this->assertMatchesRegularExpression('/\Aselect\b/i',$query['query']);
        $this->assertCount(16,$queries);$this->assertCount(2,array_filter($queries,fn(array $query):bool=>str_contains($query['query'],'tx_authority_identity_upgrade')));
        $this->assertCount(2,array_filter($queries,fn(array $query):bool=>str_contains($query['query'],'tx_authority_ticket_upgrade')));
        $this->assertSame($before,$this->fenceState());$this->assertSame('committed_prepare',$recover['recovery_status']);
        foreach(['global_writer_coverage_complete','global_drain_complete','target_authority_active','migration_gate_open']as$key)$this->assertFalse($status[$key]);
        $this->assertSame(['admin.user.create','admin.user.update'],$status['covered_writers']);
    }
    public function test_corrupt_state_receipt_and_extra_singleton_are_fixed_safe_refusals():void
    {
        $this->fence()->initialize();$state=(array)DB::table('tx_authority_writer_fence')->first();
        DB::table('tx_authority_writer_fence')->update(['content_hash'=>str_repeat('0',64)]);
        $this->refused(fn()=>$this->fence()->status(),'AUTHORITY_FENCE_STATE_INVALID');DB::table('tx_authority_writer_fence')->update($state);
        $extra=$state;$extra['id']=2;DB::table('tx_authority_writer_fence')->insert($extra);
        $this->refused(fn()=>$this->fence()->status(),'AUTHORITY_FENCE_STATE_INVALID');DB::table('tx_authority_writer_fence')->where('id',2)->delete();
        $this->fence()->prepare('corrupt-receipt','1');DB::table('tx_authority_prepare_receipt')->update(['request_hash'=>str_repeat('f',64)]);
        $this->refused(fn()=>$this->fence()->recover('corrupt-receipt','1'),'AUTHORITY_FENCE_RECEIPT_INVALID');
    }
    public function test_receipt_is_immutable_and_additive_schema_replay_preserves_populated_history():void
    {
        $this->fence()->initialize();$this->fence()->prepare('schema-replay','1');$before=$this->fenceState();
        $receipt=AuthorityPrepareReceipt::findOrFail('schema-replay');
        $this->refused(fn()=>$receipt->update(['expected_epoch'=>2]),'AUTHORITY_FENCE_RECEIPT_IMMUTABLE');
        $this->refused(fn()=>$receipt->delete(),'AUTHORITY_FENCE_RECEIPT_IMMUTABLE');
        $migration=require database_path('migrations/2026_10_14_000001_create_authority_writer_fence.php');$migration->up();$this->assertSame($before,$this->fenceState());
        try{$migration->down();$this->fail('Expected populated rollback refusal');}catch(\RuntimeException $error){$this->assertSame('AUTHORITY_FENCE_POPULATED_ROLLBACK_REFUSED',$error->getMessage());}
    }
    public function test_closed_cli_context_and_epoch_are_strict_and_not_request_controlled():void
    {
        foreach([[],['--key'=>'bad'],['--expected-epoch'=>'1']]as$extra){
            $operation=$extra===[]?'unknown':'init';$this->assertSame(1,Artisan::call('core:authority-fence',['operation'=>$operation,'--isolated-copy'=>true]+$extra));
        }
        $this->assertSame(1,Artisan::call('core:authority-fence',['operation'=>'init']));
        $this->assertSame(0,Artisan::call('core:authority-fence',['operation'=>'init','--isolated-copy'=>true]));
        foreach(['0','01','1.0','9223372036854775808']as$bad)$this->refused(fn()=>$this->fence()->prepare('epoch-input',$bad),'AUTHORITY_FENCE_EPOCH_INVALID');
        $this->refused(fn()=>$this->fence()->prepare('bad/key','1'),'AUTHORITY_FENCE_KEY_INVALID');
        DB::transaction(fn()=>$this->refused(fn()=>$this->fence()->status(),'AUTHORITY_FENCE_OUTER_TRANSACTION_UNSUPPORTED'));
        config(['authority_writer_fence.enabled'=>'true']);$this->refused(fn()=>$this->fence()->status(),'AUTHORITY_FENCE_ISOLATED_REQUIRED');
        config(['authority_writer_fence.enabled'=>true]);$this->app['env']='production';$this->refused(fn()=>$this->fence()->status(),'AUTHORITY_FENCE_ISOLATED_REQUIRED');
    }
    public function test_original_native_permissions_are_checked_before_fence_admission():void
    {
        $user=$this->user();Sanctum::actingAs($user);$this->createAccount()->assertForbidden();
        Sanctum::actingAs($this->admin);$this->postJson('/txapi/admin/wrong/users',$this->createFields())->assertNotFound();
        auth()->forgetGuards();$this->createAccount()->assertForbidden();$this->assertSame(0,DB::table('tx_authority_writer_fence')->count());
    }

    public function test_real_mysql_admitted_native_update_commits_before_prepare():void{$this->ordering('commit');}
    public function test_real_mysql_admitted_native_update_rollback_unblocks_prepare_without_mutation():void{$this->ordering('rollback');}
    public function test_real_mysql_killed_admitted_native_update_rolls_back_and_unblocks_prepare():void{$this->ordering('kill');}
    public function test_real_mysql_prepare_killed_before_commit_recovers_inconclusive_and_exact_retry_succeeds():void{$this->killPrepare(false);}
    public function test_real_mysql_prepare_committed_before_output_loss_recovers_same_receipt():void{$this->killPrepare(true);}

    public function test_native_writer_refuses_missing_receipt_storage_or_orphan_receipt_before_business_mutation():void
    {
        $this->fence()->initialize();$before=$this->businessState();
        Schema::drop('tx_authority_prepare_receipt');$this->createAccount()->assertStatus(409);$this->assertSame($before,$this->businessState());
        $migration=require database_path('migrations/2026_10_14_000001_create_authority_writer_fence.php');$migration->up();
        DB::table('tx_authority_prepare_receipt')->insert(['prepare_key'=>'orphan','scope'=>AuthorityWriterFence::SCOPE,
            'version'=>AuthorityWriterFence::VERSION,'expected_epoch'=>1,'prepared_epoch'=>2,'request_hash'=>str_repeat('a',64),
            'prior_state_hash'=>str_repeat('b',64),'prepared_state_hash'=>str_repeat('c',64),'captured_at'=>time(),'content_hash'=>str_repeat('d',64)]);
        $this->createAccount()->assertStatus(409);$this->assertSame($before,$this->businessState());
    }

    public function test_exact_prepare_replay_rejects_extra_receipt_rows_instead_of_bypassing_closed_history():void
    {
        $this->fence()->initialize();$this->fence()->prepare('original-prepare','1');
        $extra=(array)DB::table('tx_authority_prepare_receipt')->first();$extra['prepare_key']='unknown-extra';
        DB::table('tx_authority_prepare_receipt')->insert($extra);
        $this->refused(fn()=>$this->fence()->prepare('original-prepare','1'),'AUTHORITY_FENCE_RECEIPT_INVALID');
        $this->createAccount()->assertStatus(409);
    }

    private function ordering(string $mode):void
    {
        $this->requireMysqlProcesses();$this->fence()->initialize();$user=$this->user();$name=$this->connection();DB::disconnect($name);
        [$writer,$writerSocket]=$this->spawn(function($socket)use($user):void{
            DB::listen(function($query)use($socket):void{
                if(preg_match('/^update.*tx_user/i',$query->sql)){
                    $this->send($socket,['stage'=>'native_update_in_transaction']);$command=$this->receive($socket);
                    if($command['action']==='rollback')throw new \RuntimeException('synthetic-native-rollback');
                }
            });
            $response=$this->updateAccount($user->id,['remarks'=>'admitted-transaction']);$this->send($socket,['status'=>$response->status()]);
        });
        $preparer=null;$prepareSocket=null;
        try{
            $this->assertSame('native_update_in_transaction',$this->receive($writerSocket)['stage']);
            [$preparer,$prepareSocket]=$this->spawn(function($socket):void{
                $this->send($socket,['stage'=>'prepare_attempting']);$this->send($socket,['result'=>$this->fence()->prepare('ordered-prepare','1')]);
            });
            $this->assertSame('prepare_attempting',$this->receive($prepareSocket)['stage']);
            $ready=[$prepareSocket];$write=null;$except=null;$this->assertSame(0,stream_select($ready,$write,$except,0,150000),'Prepare completed before admitted writer ended.');
            if($mode==='kill'){posix_kill($writer,SIGKILL);pcntl_waitpid($writer,$status);$writer=null;}
            else{$this->send($writerSocket,['action'=>$mode]);$this->assertSame($mode==='commit'?200:500,$this->receive($writerSocket)['status']);pcntl_waitpid($writer,$status);$writer=null;}
            $result=$this->receive($prepareSocket);$this->assertArrayHasKey('result',$result);$this->assertSame('prepared',$result['result']['phase']);
            pcntl_waitpid($preparer,$status);$preparer=null;
            $this->assertSame($mode==='commit'?'admitted-transaction':null,User::findOrFail($user->id)->remarks);
            $this->assertSame('committed_prepare',$this->fence()->recover('ordered-prepare','1')['recovery_status']);
        }finally{
            foreach([$writer,$preparer]as$pid)if($pid){posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);}
            fclose($writerSocket);if(is_resource($prepareSocket))fclose($prepareSocket);DB::purge($name);
        }
    }
    private function killPrepare(bool $afterCommit):void
    {
        $this->requireMysqlProcesses();$this->fence()->initialize();$name=$this->connection();DB::disconnect($name);
        [$pid,$socket]=$this->spawn(function($socket)use($afterCommit):void{
            if(!$afterCommit)DB::listen(function($query)use($socket):void{
                if(preg_match('/^insert.*tx_authority_prepare_receipt/i',$query->sql)){
                    $this->send($socket,['stage'=>'receipt_inserted_before_commit']);$this->receive($socket);
                }
            });
            $result=$this->fence()->prepare('kill-prepare','1');
            $this->send($socket,['stage'=>'committed_output_withheld','receipt_hash'=>$result['receipt']['content_hash']]);$this->receive($socket);
        });
        try{
            $message=$this->receive($socket);$this->assertSame($afterCommit?'committed_output_withheld':'receipt_inserted_before_commit',$message['stage']);
            posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);$pid=null;
            $recovery=$this->fence()->recover('kill-prepare','1');
            $this->assertSame($afterCommit?'committed_prepare':'inconclusive_no_committed_prepare',$recovery['recovery_status']);
            $retry=$this->fence()->prepare('kill-prepare','1');$this->assertSame($afterCommit,$retry['replayed']);
            if($afterCommit)$this->assertSame($message['receipt_hash'],$retry['receipt']['content_hash']);
            $this->assertSame(1,DB::table('tx_authority_prepare_receipt')->count());
        }finally{if($pid){posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);}fclose($socket);DB::purge($name);}
    }
    private function spawn(callable $task):array
    {
        $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);$this->assertIsArray($pair);
        $pid=pcntl_fork();$this->assertNotSame(-1,$pid);
        if($pid===0){fclose($pair[0]);DB::purge($this->connection());
            try{$task($pair[1]);}catch(\Throwable $error){$this->send($pair[1],['child_error'=>get_class($error),'code'=>$error instanceof AuthorityFenceException?$error->getMessage():'fixture_failure']);}
            fclose($pair[1]);exit(0);
        }
        fclose($pair[1]);return[$pid,$pair[0]];
    }
    private function send($socket,array $payload):void{fwrite($socket,json_encode($payload,JSON_THROW_ON_ERROR)."\n");fflush($socket);}
    private function receive($socket):array
    {
        stream_set_timeout($socket,12);$line=fgets($socket);$this->assertIsString($line,'Independent process did not respond.');return json_decode($line,true,flags:JSON_THROW_ON_ERROR);
    }
    private function requireMysqlProcesses():void
    {if(!$this->mysql()||!function_exists('pcntl_fork')||!function_exists('posix_kill'))$this->markTestSkipped('Requires dedicated MySQL independent processes and POSIX SIGKILL.');}
    private function firstQuery(array $queries,string $table):int
    {foreach($queries as$i=>$query)if(str_contains($query['query'],$table))return$i;$this->fail('Expected actual query');}
    private function fence():AuthorityWriterFence{return app(AuthorityWriterFence::class);}
    private function createFields():array{return['email_prefix'=>'new-fence-user','email_suffix'=>'example.test','password'=>'SyntheticFencePassword77'];}
    private function createAccount():\Illuminate\Testing\TestResponse{return$this->postJson('/txapi/admin/authority_fixture/users',$this->createFields());}
    private function updateAccount(int $id,array $fields):\Illuminate\Testing\TestResponse{return$this->postJson('/txapi/admin/authority_fixture/users/'.$id.'/update',$fields);}
    private function user(array $attributes=[]):User
    {return User::withoutEvents(fn()=>User::create(array_replace(['email'=>bin2hex(random_bytes(6)).'@example.test','password'=>'synthetic-no-login','uuid'=>bin2hex(random_bytes(16)),'token'=>bin2hex(random_bytes(16))],$attributes)));}
    private function businessState():string{return DB::table('tx_user')->orderBy('id')->get()->toJson();}
    private function fenceState():array{return[DB::table('tx_authority_writer_fence')->get()->toJson(),DB::table('tx_authority_prepare_receipt')->get()->toJson()];}
    private function refused(callable $call,string $code):void
    {try{$call();$this->fail('Expected fence refusal');}catch(AuthorityFenceException $error){$this->assertSame($code,$error->getMessage());$this->assertNull($error->getPrevious());}}
    private function mysql():bool{return getenv('CORE_AUTHORITY_FENCE_TEST_MYSQL')==='1';}
    private function connection():string{return$this->mysql()?'authority_mysql_testing':'authority_sqlite_testing';}
    private function clearMysql():void
    {
        if(DB::connection()->getDatabaseName()!=='txboard_authority_fence_test')throw new \RuntimeException('Authority fence requires its own disposable database.');
        Schema::disableForeignKeyConstraints();try{foreach(Schema::getTableListing()as$table)Schema::drop($table);}finally{Schema::enableForeignKeyConstraints();}
    }
}
