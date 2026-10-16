<?php

namespace Tests\Feature\AuthorityMigration;

use App\Console\Commands\CheckCommission;
use App\Domains\AuthorityMigration\AuthorityAdmissionRegistry;
use App\Domains\AuthorityMigration\AuthorityFenceException;
use App\Domains\AuthorityMigration\AuthorityIdentityInventory;
use App\Domains\AuthorityMigration\AuthorityNativeInventory;
use App\Domains\AuthorityMigration\AuthorityPostcommitHandoff;
use App\Domains\AuthorityMigration\AuthorityPostcommitIntent;
use App\Domains\AuthorityMigration\AuthorityWriterFence;
use App\Jobs\NodeUserSyncJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TrafficResetService;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorityNativeWritersTest extends TestCase
{
    private User $admin;
    private \Illuminate\Contracts\Bus\Dispatcher $originalBus;
    protected function setUp():void
    {
        parent::setUp();$name=$this->connection();
        config(['database.default'=>$name,'database.connections.'.$name=>$this->mysql()?config('database.connections.mysql'):
            array_replace(config('database.connections.sqlite'),['database'=>':memory:'])]);DB::purge($name);
        if($this->mysql())$this->clearMysql();$this->assertSame(0,Artisan::call('migrate',['--force'=>true]));
        $this->originalBus=app(\Illuminate\Contracts\Bus\Dispatcher::class);Bus::fake();HookManager::reset();
        config(['authority_writer_fence'=>['enabled'=>true,'worker_epoch'=>'1','worker_role'=>'legacy'],'core_shadow.isolated_copy'=>true]);
        $this->fixtureSettings(['secure_path'=>'native_fixture','commission_transfer_limit'=>0,'commission_withdraw_limit'=>0,'try_out_plan_id'=>0]);
        $this->admin=$this->user(['is_admin'=>true]);Sanctum::actingAs($this->admin);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Redis::shouldReceive('sadd')->zeroOrMoreTimes()->andReturn(1);
    }
    protected function tearDown():void
    {HookManager::reset();if($this->mysql())$this->clearMysql();DB::purge($this->connection());parent::tearDown();}

    public function test_extension_fixed26_scope_exact_replay_prepare5_and_old_prepared_histories_never_reopen():void
    {
        $this->native();$status=$this->fence()->status();$this->assertSame('4',$status['authority_epoch']);$this->assertCount(26,$status['covered_writers']);
        $this->assertTrue($this->fence()->extendNative('native-fixture','3')['replayed']);$before=$this->history();
        $prepared=$this->fence()->prepare('prepared-native','4');$this->assertSame('5',$prepared['authority_epoch']);
        $this->assertSame($prepared['receipt'],$this->fence()->prepare('prepared-native','4')['receipt']);
        $this->refused(fn()=>$this->fence()->extendNative('different','3'),'AUTHORITY_NATIVE_REQUEST_CONFLICT');
        $this->assertSame($before['finance'],$this->history()['finance']);
        foreach(['global_writer_coverage_complete','global_drain_complete','target_authority_active','migration_gate_open']as$key)$this->assertFalse($status[$key]);
    }
    public function test_identity_extension_receipt_roundtrips_and_admits_only_declared_epoch5_writers():void
    {
        $this->native();$this->assertSame('4',$this->fence()->status()['authority_epoch']);
        $extended=$this->fence()->extendIdentity('identity-fixture','4');
        $this->assertSame('5',$extended['authority_epoch']);$this->assertFalse($extended['replayed']);
        $status=$this->fence()->status();$this->assertSame('5',$status['authority_epoch']);
        $this->assertSame(AuthorityIdentityInventory::SCOPE,$status['scope']);
        $this->assertTrue($status['read_only']);$this->assertSame(1,DB::table('tx_authority_identity_upgrade')->count());
        $replay=$this->fence()->extendIdentity('identity-fixture','4');
        $this->assertTrue($replay['replayed']);$this->assertSame($extended['identity_receipt'],$replay['identity_receipt']);

        config(['authority_writer_fence'=>['enabled'=>true,'worker_epoch'=>'5','worker_role'=>'legacy',
            'worker_scope'=>AuthorityIdentityInventory::SCOPE,'worker_version'=>AuthorityIdentityInventory::VERSION]]);
        DB::transaction(function():void{$this->assertSame('5',$this->fence()->admit('user.login.touch')->epoch);});
        DB::transaction(fn()=>$this->refused(fn()=>$this->fence()->admit('unknown.identity.writer'),'AUTHORITY_FENCE_WRITER_INVALID'));
        $prepared=$this->fence()->prepare('identity-stop','5');$this->assertSame('6',$prepared['authority_epoch']);
        $recovered=$this->fence()->recoverIdentity('identity-fixture','4');
        $this->assertSame('committed_identity',$recovered['recovery_status']);$this->assertTrue($recovered['read_only']);
        $this->assertSame($extended['identity_receipt'],$recovered['identity_receipt']);
    }
    public function test_actual_plan_and_admin_order_original_transactions_admit_and_prepared5_blocks_without_mutation():void
    {
        $this->native();$plan=$this->plan();$other=$this->plan();$buyer=$this->user();$inviter=$this->user();
        $this->postJson('/txapi/admin/native_fixture/plans/'.$plan->id.'/flags',['show'=>false])->assertOk();
        $this->postJson('/txapi/admin/native_fixture/plans/sort',['ids'=>[$other->id,$plan->id]])->assertOk();
        $this->assertSame(1,(int)$other->fresh()->sort);
        $this->postJson('/txapi/admin/native_fixture/orders/assign',['email'=>$buyer->email,'plan_id'=>$plan->id,'period'=>'month_price','total_amount'=>100])->assertCreated();
        $review=$this->order($buyer,$inviter,['commission_status'=>0]);
        $this->postJson('/txapi/admin/native_fixture/orders/'.$review->trade_no.'/commission-review',['commission_status'=>1])->assertOk();
        $unused=$this->plan();$this->postJson('/txapi/admin/native_fixture/plans/'.$unused->id.'/delete')->assertOk();
        $this->fence()->prepare('native-stop','4');$this->declare('5');$before=$this->business();
        $this->postJson('/txapi/admin/native_fixture/plans/'.$plan->id.'/flags',['show'=>true])->assertStatus(409);
        $this->postJson('/txapi/admin/native_fixture/plans/sort',['ids'=>[$plan->id,$other->id]])->assertStatus(409);
        $this->postJson('/txapi/admin/native_fixture/plans/'.$other->id.'/delete')->assertStatus(409);
        $this->postJson('/txapi/admin/native_fixture/orders/assign',['email'=>$inviter->email,'plan_id'=>$plan->id,'period'=>'month_price','total_amount'=>100])->assertStatus(409);
        $this->postJson('/txapi/admin/native_fixture/orders/'.$review->trade_no.'/commission-review',['commission_status'=>0])->assertStatus(409);
        $this->assertSame($before,$this->business());
    }
    public function test_actual_commission_manual_transaction_and_bulk_review_keep_native_money_and_fixed_prepared_cli_failure():void
    {
        $this->native();$buyer=$this->user();$inviter=$this->user();$order=$this->order($buyer,$inviter,['commission_status'=>0,'updated_at'=>time()-4*86400]);
        $this->fixtureSettings(['commission_auto_check_enable'=>1,'commission_distribution_enable'=>0,'withdraw_close_enable'=>0]);
        $this->assertSame(0,Artisan::call('check:commission'));$this->assertSame(100,(int)$inviter->fresh()->commission_balance);
        $this->assertSame(2,(int)$order->fresh()->commission_status);$this->assertSame(1,DB::table('tx_commission_log')->count());
        $this->fence()->prepare('cron-stop','4');$this->declare('5');
        $this->assertSame(1,Artisan::call('check:commission'));$this->assertStringContainsString('AUTHORITY_COMMISSION_FENCE_REJECTED',Artisan::output());
    }
    public function test_standalone_reset_nested_proof_and_default_legacy_fetch_are_actual_sql_writers():void
    {
        $this->native();$plan=$this->plan();$user=$this->user(['plan_id'=>$plan->id,'group_id'=>1,'expired_at'=>time()+86400,
            'u'=>10,'d'=>20,'last_reset_at'=>time()-86400,'next_reset_at'=>time()-1]);
        $service=app(TrafficResetService::class);$this->assertTrue($service->checkAndReset($user,'cron'));$this->assertSame(0,(int)$user->fresh()->u);
        DB::transaction(function()use($user,$service):void{
            $proof=$this->fence()->admit('order.fulfill');$this->assertTrue($service->performReset($user,'order'));$proof->assertValid();
        });
        DB::transaction(function()use($user,$service):void{
            $this->assertFalse($service->performReset($user,'order')); // Unknown outer transaction never acquires authority late.
        });
        $job=new TrafficFetchJob(['id'=>1,'rate'=>2],[$user->id=>[5,7]],'vmess',time());$job->handle();
        $this->assertSame(10,(int)$user->fresh()->u);$this->assertSame(14,(int)$user->fresh()->d);
        $this->fence()->prepare('traffic-stop','4');$this->declare('5');$before=$this->business();
        $this->refused(fn()=>$job->handle(),'AUTHORITY_FENCE_PREPARED');$this->assertSame($before,$this->business());
    }
    public function test_schedule_repair_default_cli_uses_per_user_fence_and_does_not_reset_counters():void
    {
        $this->native();$plan=$this->plan();$user=$this->user(['plan_id'=>$plan->id,'group_id'=>1,'expired_at'=>time()+86400,'u'=>5,'d'=>9,'next_reset_at'=>null]);
        $this->assertSame(0,Artisan::call('reset:traffic',['--fix-null'=>true]));$this->assertNotNull($user->fresh()->next_reset_at);
        $this->assertSame(5,(int)$user->fresh()->u);$this->assertSame(9,(int)$user->fresh()->d);
    }

    public function test_each_model_save_and_later_transaction_keeps_independent_intent_original_group_and_producer_changes():void
    {
        $this->native();$plan=$this->plan();$user=$this->user(['plan_id'=>$plan->id,'group_id'=>1,'expired_at'=>time()+86400]);$snapshot=null;
        DB::transaction(function()use($user,&$snapshot):void{
            $this->fence()->admit('admin.user.update');$user->update(['group_id'=>2,'expired_at'=>time()+2*86400]);
            $user->update(['group_id'=>3,'expired_at'=>time()+3*86400]);$snapshot=[$user->getAttributes(),$user->getOriginal(),$user->getChanges()];
        });
        $this->assertSame($snapshot,[$user->getAttributes(),$user->getOriginal(),$user->getChanges()]);$this->assertTrue($user->wasChanged('group_id'));
        $rows=DB::table('tx_authority_postcommit_intent')->where('legacy_user_id',$user->id)->orderBy('event_sequence')->get();
        $this->assertCount(2,$rows);$this->assertNotSame($rows[0]->intent_id,$rows[1]->intent_id);$this->assertSame([1,2],array_map('intval',$rows->pluck('old_group_id')->all()));
        DB::transaction(function()use($user):void{$this->fence()->admit('admin.user.update');$user->update(['group_id'=>4,'expired_at'=>time()+4*86400]);});
        $this->assertSame(3,DB::table('tx_authority_postcommit_intent')->where('legacy_user_id',$user->id)->count());
        Bus::assertDispatchedTimes(NodeUserSyncJob::class,3);
    }
    public function test_cancelled_model_write_and_rolled_back_savepoint_never_commit_intents_or_callbacks():void
    {
        $this->native();$user=$this->user(['group_id'=>1]);$proof=null;
        DB::transaction(function()use($user,&$proof):void{
            $proof=$this->fence()->admit('admin.user.update');DB::beginTransaction();$user->update(['group_id'=>2]);DB::rollBack();$proof->assertValid();
            $fresh=$user->fresh();$fresh->update(['group_id'=>3]);
        });
        $this->assertSame(3,(int)$user->fresh()->group_id);$this->assertSame(1,DB::table('tx_authority_postcommit_intent')->count());Bus::assertDispatchedTimes(NodeUserSyncJob::class,1);
        $this->refused(fn()=>$proof->assertValid(),'AUTHORITY_ADMISSION_LIFETIME_INVALID');
        Event::listen('eloquent.updating: '.User::class,fn()=>false);
        DB::transaction(function()use($user):void{$this->fence()->admit('admin.user.update');$this->assertFalse($user->fresh()->update(['group_id'=>4]));});
        $this->assertSame(1,DB::table('tx_authority_postcommit_intent')->count());$this->assertSame(3,(int)$user->fresh()->group_id);
    }

    public function test_proof_rejects_equal_depth_rollback_rebegin_first_nested_alternate_connection_purge_and_aftercommit():void
    {
        $this->native();DB::beginTransaction();$proof=$this->fence()->admit('admin.user.update');$proof->assertValid();DB::rollBack();
        DB::beginTransaction();$this->refused(fn()=>$proof->assertValid(),'AUTHORITY_ADMISSION_LIFETIME_INVALID');$second=$this->fence()->admit('admin.user.update');
        $this->assertNotSame($proof->outer,$second->outer);DB::rollBack();
        DB::transaction(function():void{DB::transaction(fn()=>$this->refused(fn()=>$this->fence()->admit('traffic.reset'),'AUTHORITY_ADMISSION_REQUIRED'));});
        DB::beginTransaction();$proof=$this->fence()->admit('admin.user.update');$name=$this->connection();
        config(['database.connections.native_alternate'=>config('database.connections.'.$name),'database.default'=>'native_alternate']);
        $this->refused(fn()=>$proof->assertValid(),'AUTHORITY_ADMISSION_LIFETIME_INVALID');config(['database.default'=>$name]);DB::rollBack();DB::purge('native_alternate');
        DB::beginTransaction();$proof=$this->fence()->admit('admin.user.update');
        $pdo=\WeakReference::create(DB::connection()->getPdo());DB::purge($name);
        $this->assertNull($pdo->get(),'Admission identity must not keep a purged SQL transaction physically open.');
        $this->refused(fn()=>$proof->assertValid(),'AUTHORITY_ADMISSION_LIFETIME_INVALID');app('db.transactions')->rollback($name,0);
    }
    public function test_successive_old_version_transactions_release_closed_proofs_in_scoped_long_lived_registry():void
    {
        $this->fence()->initialize();$previous=null;
        for($attempt=0;$attempt<4;++$attempt){
            DB::beginTransaction();$proof=$this->fence()->admit('admin.user.update');
            if($previous)$this->assertNull($previous->get(),'Closed admission must not retain its outer transaction in the scoped registry.');
            $proof->assertValid();$previous=\WeakReference::create($proof);unset($proof);
            if($attempt%2===0)DB::commit();else DB::rollBack();
        }
        $this->assertNotNull($previous->get());
        app(AuthorityAdmissionRegistry::class)->find(DB::connection());
        $this->assertNull($previous->get());
    }
    public function test_retry3_uses_new_real_transaction_record_and_does_not_dispatch_rolled_back_attempt():void
    {
        $this->native();$user=$this->user(['group_id'=>1]);$attempt=0;$old=null;
        DB::transaction(function()use($user,&$attempt,&$old):void{
            ++$attempt;$proof=$this->fence()->admit('admin.user.update');
            if($old)$this->refused(fn()=>$old->assertValid(),'AUTHORITY_ADMISSION_LIFETIME_INVALID');
            $user->fresh()->update(['group_id'=>2]);
            if($attempt===1){$old=$proof;throw new QueryException($this->connection(),'fixed synthetic deadlock',[],new \PDOException('Deadlock found when trying to get lock'));}
        },3);
        $this->assertSame(2,$attempt);$this->assertSame(1,DB::table('tx_authority_postcommit_intent')->count());Bus::assertDispatchedTimes(NodeUserSyncJob::class,1);
    }
    public function test_queue_acceptance_with_lost_result_remains_unknown_fixed_retry_never_claims_node_delivery():void
    {
        $this->native();$user=$this->user(['group_id'=>1]);$accepted=[];
        Bus::swap(new class($accepted){public array $accepted=[];public function __construct(&$accepted){$this->accepted=&$accepted;}
            public function dispatch($job){$this->accepted[]=$job;throw new \RuntimeException('queue accepted then result lost');}});
        DB::transaction(function()use($user):void{$this->fence()->admit('admin.user.update');$user->update(['group_id'=>2]);});
        $row=DB::table('tx_authority_postcommit_delivery')->first();$this->assertSame('unknown',$row->handoff_state);$this->assertCount(1,$accepted);
        $report=app(AuthorityPostcommitHandoff::class)->run(1);$this->assertFalse($report['node_delivery_verified']);$this->assertFalse($report['global_drain_complete']);
        $this->assertCount(2,$accepted);app(AuthorityPostcommitHandoff::class)->run(1);$this->assertCount(3,$accepted);
        $last=app(AuthorityPostcommitHandoff::class)->run(1);$this->assertSame('unknown_attempt_limit',$last['results'][0]['handoff_status']);$this->assertCount(3,$accepted);
    }
    public function test_default_off_keeps_original_aftercommit_callbacks_and_queries_with_all_native_tables_missing():void
    {
        config(['authority_writer_fence'=>['enabled'=>false,'worker_epoch'=>'bad','worker_role'=>'bad'],'core_shadow.isolated_copy'=>false]);$this->app['env']='production';
        foreach(['tx_authority_postcommit_delivery','tx_authority_postcommit_intent','tx_authority_native_upgrade']as$table)Schema::drop($table);
        $user=$this->user();$plan=$this->plan();DB::enableQueryLog();DB::flushQueryLog();
        $this->postJson('/txapi/admin/native_fixture/users/'.$user->id.'/update',['plan_id'=>$plan->id,'expected_plan_id'=>null,
            'expired_at'=>time()+86400,'expected_expired_at'=>null])->assertOk();
        $queries=DB::getQueryLog();DB::disableQueryLog();
        foreach($queries as$q){
            if(str_contains($q['query'],'sqlite_master')||str_contains($q['query'],'information_schema')
                ||str_contains($q['query'],'pragma_table_xinfo'))continue;
            // The reused singleton is now also the installation admission record.
            if(preg_match('/^select .* from ["`]tx_authority_writer_fence["`] order by /i',$q['query']))continue;
            $this->assertStringNotContainsString('tx_authority_',$q['query']);
        }
        $this->assertNotNull($user->fresh()->next_reset_at);Bus::assertDispatched(NodeUserSyncJob::class);
    }
    public function test_intents_are_closed_nonsecret_immutable_schema_replay_safe_and_cli_handoff_bounded():void
    {
        $this->native();$user=$this->user(['group_id'=>1]);DB::transaction(function()use($user):void{$this->fence()->admit('admin.user.update');$user->update(['group_id'=>2]);});
        $row=DB::table('tx_authority_postcommit_intent')->first();$text=json_encode($row);
        foreach([$user->email,$user->password,$user->token,$user->uuid]as$secret)$this->assertStringNotContainsString($secret,$text);
        $intent=AuthorityPostcommitIntent::findOrFail($row->intent_id);$this->refused(fn()=>$intent->update(['writer'=>'other']),'AUTHORITY_POSTCOMMIT_INTENT_IMMUTABLE');
        $this->refused(fn()=>$intent->delete(),'AUTHORITY_POSTCOMMIT_INTENT_IMMUTABLE');
        $migration=require database_path('migrations/2026_10_14_000005_extend_native_writer_authority_and_schedule_intents.php');$migration->up();
        try{$migration->down();$this->fail('Expected populated guard');}catch(\RuntimeException $e){$this->assertSame('AUTHORITY_NATIVE_POPULATED_ROLLBACK_REFUSED',$e->getMessage());}
        $this->assertSame(1,Artisan::call('core:authority-postcommit-handoff'));$this->assertSame(0,Artisan::call('core:authority-postcommit-handoff',['--isolated-copy'=>true,'--limit'=>1]));
    }

    public function test_real_mysql_pid_fork_proof_cannot_authorize_child_process():void
    {
        $this->requireMysql();$this->native();DB::beginTransaction();$proof=$this->fence()->admit('admin.user.update');
        [$parent,$child]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);$pid=pcntl_fork();
        if($pid===0){fclose($parent);try{$proof->assertValid();$result='unexpected';}catch(AuthorityFenceException $e){$result=$e->getMessage();}
            fwrite($child,$result."\n");fflush($child);posix_kill(getmypid(),SIGKILL);}
        fclose($child);stream_set_timeout($parent,10);$this->assertSame("AUTHORITY_ADMISSION_LIFETIME_INVALID\n",fgets($parent));pcntl_waitpid($pid,$status);fclose($parent);$proof->assertValid();DB::rollBack();
    }
    public function test_real_mysql_schedule_and_intent_commit_wait_before_prepare5():void{$this->ordering('commit');}
    public function test_real_mysql_killed_schedule_producer_rolls_back_schedule_intents_and_unblocks_prepare():void{$this->ordering('kill');}
    public function test_real_mysql_commit_failure_from_connection_loss_rolls_back_intents_and_invalidates_proof():void
    {
        $this->requireMysql();$this->native();$user=$this->user(['group_id'=>1]);$name=$this->connection();
        config(['database.connections.native_killer'=>config('database.connections.'.$name)]);DB::purge('native_killer');$other=DB::connection('native_killer');
        $connectionId=(int)DB::selectOne('SELECT CONNECTION_ID() AS id')->id;$proof=null;$killed=false;
        Event::listen(TransactionCommitting::class,function($event)use($other,$connectionId,&$killed,$name):void{
            if(!$killed&&$event->connection->getName()===$name){$killed=true;$other->statement('KILL CONNECTION '.$connectionId);}
        });
        try{DB::transaction(function()use($user,&$proof):void{$proof=$this->fence()->admit('admin.user.update');$user->update(['group_id'=>2]);});$this->fail('Expected actual lost commit');}
        catch(\Throwable $error){$this->assertTrue($killed);$this->refused(fn()=>$proof->assertValid(),'AUTHORITY_ADMISSION_LIFETIME_INVALID');}
        DB::purge($name);app('db.transactions')->rollback($name,0);$this->assertSame(1,(int)$user->fresh()->group_id);
        $this->assertSame(0,DB::table('tx_authority_postcommit_intent')->count());Bus::assertNothingDispatched();DB::purge('native_killer');
    }
    public function test_real_mysql_queue_enqueue_then_sigkill_preserves_unknown_handoff_and_fixed_duplicate_retry():void
    {
        $this->requireMysql();$this->native();$user=$this->user(['group_id'=>1]);$this->databaseQueue();$name=$this->connection();DB::disconnect($name);
        [$pid,$socket]=$this->spawn(function($socket)use($user):void{
            DB::listen(function($query)use($socket):void{if(preg_match('/^insert.*tx_native_fence_jobs/i',$query->sql)){
                $this->send($socket,['stage'=>'durable_job_inserted']);$this->receive($socket);
            }});
            DB::transaction(function()use($user):void{$this->fence()->admit('admin.user.update');$user->update(['group_id'=>2]);});
        });
        try{
            $this->assertSame('durable_job_inserted',$this->receive($socket)['stage']);posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);$pid=null;
            $this->assertSame(1,DB::table('tx_native_fence_jobs')->count());$this->assertSame(2,(int)$user->fresh()->group_id);
            $delivery=DB::table('tx_authority_postcommit_delivery')->first();$this->assertSame('processing',$delivery->handoff_state);
            DB::table('tx_authority_postcommit_delivery')->update(['lease_expires_at'=>time()-1]);
            $report=app(AuthorityPostcommitHandoff::class)->run(1);$this->assertFalse($report['node_delivery_verified']);$this->assertSame(2,DB::table('tx_native_fence_jobs')->count());
            $this->assertSame('handed_off',DB::table('tx_authority_postcommit_delivery')->first()->handoff_state);
        }finally{if($pid){posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);}fclose($socket);DB::purge($name);}
    }
    private function ordering(string $mode):void
    {
        $this->requireMysql();$this->native();$plan=$this->plan();$user=$this->user(['plan_id'=>$plan->id,'group_id'=>1,'expired_at'=>time()+86400]);$name=$this->connection();DB::disconnect($name);
        [$writer,$socket]=$this->spawn(function($socket)use($user):void{
            DB::listen(function($query)use($socket):void{if(preg_match('/^update.*tx_authority_postcommit_delivery/i',$query->sql)&&str_contains($query->sql,'schedule_state')){
                $this->send($socket,['stage'=>'schedule_sql_and_intents_before_commit']);$this->receive($socket);
            }});
            $response=$this->postJson('/txapi/admin/native_fixture/users/'.$user->id.'/update',['expired_at'=>time()+2*86400,'expected_expired_at'=>$user->expired_at]);
            $this->send($socket,['status'=>$response->status()]);
        });$control=null;$controlSocket=null;
        try{
            $this->assertSame('schedule_sql_and_intents_before_commit',$this->receive($socket)['stage']);
            [$control,$controlSocket]=$this->spawn(function($socket):void{$this->send($socket,['stage'=>'prepare_attempt']);$this->send($socket,['result'=>$this->fence()->prepare('ordered-native','4')]);});
            $this->assertSame('prepare_attempt',$this->receive($controlSocket)['stage']);$read=[$controlSocket];$write=null;$except=null;$this->assertSame(0,stream_select($read,$write,$except,0,150000));
            if($mode==='kill'){posix_kill($writer,SIGKILL);pcntl_waitpid($writer,$status);$writer=null;}
            else{$this->send($socket,['action'=>'commit']);$this->assertSame(200,$this->receive($socket)['status']);pcntl_waitpid($writer,$status);$writer=null;}
            $result=$this->receive($controlSocket);$this->assertSame('5',$result['result']['authority_epoch']);pcntl_waitpid($control,$status);$control=null;
            $this->assertSame($mode==='commit'?1:0,DB::table('tx_authority_postcommit_intent')->count());
            if($mode==='commit')$this->assertNotNull($user->fresh()->next_reset_at);else$this->assertNull($user->fresh()->next_reset_at);
        }finally{foreach([$writer,$control]as$pid)if($pid){posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);}fclose($socket);if(is_resource($controlSocket))fclose($controlSocket);DB::purge($name);}
    }
    private function databaseQueue():void
    {
        Schema::create('tx_native_fence_jobs',function($t){$t->bigIncrements('id');$t->string('queue');$t->longText('payload');$t->unsignedTinyInteger('attempts');$t->unsignedInteger('reserved_at')->nullable();$t->unsignedInteger('available_at');$t->unsignedInteger('created_at');});
        config(['queue.default'=>'native_fence_database','queue.connections.native_fence_database'=>['driver'=>'database','connection'=>$this->connection(),'table'=>'tx_native_fence_jobs','queue'=>'node_sync','retry_after'=>30,'after_commit'=>false]]);
        Bus::swap($this->originalBus); // The unique named queue connection has not been resolved before this fixture.
    }
    private function spawn(callable $task):array
    {
        $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);$this->assertIsArray($pair);$pid=pcntl_fork();$this->assertNotSame(-1,$pid);
        if($pid===0){fclose($pair[0]);DB::purge($this->connection());try{$task($pair[1]);}catch(\Throwable $e){$this->send($pair[1],['child_error'=>get_class($e),'code'=>$e instanceof AuthorityFenceException?$e->getMessage():'fixture_failure']);}fclose($pair[1]);exit(0);}fclose($pair[1]);return[$pid,$pair[0]];
    }
    private function send($socket,array $value):void{fwrite($socket,json_encode($value,JSON_THROW_ON_ERROR)."\n");fflush($socket);}
    private function receive($socket):array{stream_set_timeout($socket,15);$line=fgets($socket);$this->assertIsString($line);return json_decode($line,true,flags:JSON_THROW_ON_ERROR);}
    private function requireMysql():void{if(!$this->mysql()||!function_exists('pcntl_fork')||!function_exists('posix_kill'))$this->markTestSkipped('Requires dedicated MySQL processes and SIGKILL.');}

    private function native():void{$this->fence()->initialize();$this->fence()->expand('inventory-fixture','1');$this->fence()->extendFinance('finance-fixture','2');$this->fence()->extendNative('native-fixture','3');$this->declare();}
    private function declare(string $epoch='4'):void{config(['authority_writer_fence'=>['enabled'=>true,'worker_epoch'=>$epoch,'worker_role'=>'legacy','worker_scope'=>AuthorityNativeInventory::SCOPE,'worker_version'=>AuthorityNativeInventory::VERSION]]);}
    private function fence():AuthorityWriterFence{return app(AuthorityWriterFence::class);}
    private function user(array $attrs=[]):User{return User::withoutEvents(fn()=>User::create(array_replace(['email'=>bin2hex(random_bytes(6)).'@example.test','password'=>'synthetic-no-login','uuid'=>bin2hex(random_bytes(16)),'token'=>bin2hex(random_bytes(16)),'u'=>0,'d'=>0,'balance'=>0,'commission_balance'=>0,'transfer_enable'=>1000,'reset_count'=>0,'plan_id'=>null,'expired_at'=>null,'next_reset_at'=>null,'last_reset_at'=>null],$attrs)));}
    private function plan(array $attrs=[]):Plan{return Plan::create(array_replace(['name'=>'Native Plan','group_id'=>1,'transfer_enable'=>1,'prices'=>['monthly'=>1],'reset_traffic_method'=>1,'show'=>true,'sell'=>true,'renew'=>true,'capacity_limit'=>null],$attrs));}
    private function order(User $buyer,User $inviter,array $attrs=[]):Order{return Order::create(array_replace(['user_id'=>$buyer->id,'invite_user_id'=>$inviter->id,'plan_id'=>1,'type'=>Order::TYPE_NEW_PURCHASE,'period'=>'monthly','trade_no'=>'native-order-'.bin2hex(random_bytes(6)),
        'total_amount'=>1000,'commission_balance'=>100,'actual_commission_balance'=>0,'commission_status'=>1,'status'=>Order::STATUS_COMPLETED],$attrs));}
    private function history():array{$out=[];foreach(['tx_authority_writer_fence','tx_authority_prepare_receipt','tx_authority_inventory_upgrade','tx_authority_finance_upgrade','tx_authority_native_upgrade']as$table)$out[str_contains($table,'finance')?'finance':$table]=DB::table($table)->get()->toJson();return$out;}
    private function business():array{$out=[];foreach(['tx_user','tx_plan','tx_order','tx_commission_log','tx_authority_postcommit_intent','tx_authority_postcommit_delivery']as$table)$out[$table]=DB::table($table)->orderBy($table==='tx_authority_postcommit_intent'||$table==='tx_authority_postcommit_delivery'?'intent_id':'id')->get()->toJson();return$out;}
    private function refused(callable $call,string $code):void{try{$call();$this->fail('Expected refusal');}catch(AuthorityFenceException $e){$this->assertSame($code,$e->getMessage());$this->assertNull($e->getPrevious());}}
    /** Seed inputs under native settings policy, then restore the tested v4/v5 declaration. */
    private function fixtureSettings(array $settings):void
    {
        $enabled=config('authority_writer_fence.enabled');config(['authority_writer_fence.enabled'=>false]);
        try{admin_setting($settings);}finally{config(['authority_writer_fence.enabled'=>$enabled]);}
    }
    private function mysql():bool{return getenv('CORE_NATIVE_FENCE_TEST_MYSQL')==='1';}
    private function connection():string{return$this->mysql()?'native_fence_mysql':'native_fence_sqlite';}
    private function clearMysql():void{if(DB::connection()->getDatabaseName()!=='txboard_native_fence_test')throw new \RuntimeException('Native fence needs its dedicated database.');Schema::disableForeignKeyConstraints();try{foreach(Schema::getTableListing()as$table)Schema::drop($table);}finally{Schema::enableForeignKeyConstraints();}}
}
