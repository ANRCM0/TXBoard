<?php

namespace Tests\Feature\AuthorityMigration;

use App\Domains\AuthorityMigration\AuthorityFenceException;
use App\Domains\AuthorityMigration\AuthorityFinanceInventory;
use App\Domains\AuthorityMigration\AuthorityFinanceUpgrade;
use App\Domains\AuthorityMigration\AuthorityInventory;
use App\Domains\AuthorityMigration\AuthorityWriterFence;
use App\Domains\Billing\OrderCheckout;
use App\Domains\Billing\WalletRechargeService;
use App\Jobs\OrderHandleJob;
use App\Models\Coupon;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Plugin;
use App\Models\User;
use App\Models\WalletRecharge;
use App\Services\OrderService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

class AuthorityFinanceFenceTest extends TestCase
{
    private TestHandler $log;
    protected function setUp():void
    {
        parent::setUp();$name=$this->connection();
        config(['database.default'=>$name,'database.connections.'.$name=>$this->mysql()?config('database.connections.mysql'):
            array_replace(config('database.connections.sqlite'),['database'=>':memory:'])]);DB::purge($name);
        if($this->mysql())$this->clearMysql();$this->assertSame(0,Artisan::call('migrate',['--force'=>true]));Bus::fake();HookManager::reset();
        config(['authority_writer_fence'=>['enabled'=>true,'worker_epoch'=>'1','worker_role'=>'legacy'],'core_shadow.isolated_copy'=>true,
            'legacy_capture_shadow.enabled'=>false,'legacy_admin_creation_shadow.enabled'=>false,'legacy_observer_capture.enabled'=>false]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Plugin::create(['name'=>'EPay','code'=>'epay','type'=>'payment','version'=>'1.0.0','is_enabled'=>true,'config'=>'{}']);
        $this->app->forgetInstance(PluginManager::class);app(PluginManager::class)->initializeEnabledPlugins();
        admin_setting(['commission_transfer_limit'=>0,'commission_withdraw_limit'=>0]);
        $this->log=new TestHandler();Log::swap(new Logger('finance-fence',[$this->log]));
    }
    protected function tearDown():void
    {HookManager::reset();if($this->mysql())$this->clearMysql();DB::purge($this->connection());parent::tearDown();}

    public function test_extension_same_row_parent_history_exact_replay_and_new_prepare_epoch4():void
    {
        $this->v2();$prior=$this->history();$first=$this->fence()->extendFinance('finance-fixture','2');$this->newWorker();
        $again=$this->fence()->extendFinance('finance-fixture','2');$this->assertFalse($first['replayed']);$this->assertTrue($again['replayed']);
        $this->assertSame($first['finance_receipt'],$again['finance_receipt']);$this->assertSame('3',$first['authority_epoch']);
        $this->assertSame(AuthorityFinanceInventory::WRITERS,$first['covered_writers']);$this->assertCount(16,$first['covered_writers']);
        $this->assertSame($prior['inventory'],$this->history()['inventory']);$this->assertSame(1,DB::table('tx_authority_writer_fence')->count());
        $prepared=$this->fence()->prepare('finance-prepare','3');$this->assertSame('4',$prepared['authority_epoch']);
        $this->assertSame('3',$prepared['receipt']['expected_epoch']);$this->assertSame('4',$prepared['receipt']['prepared_epoch']);
        $this->assertSame($prepared['receipt'],$this->fence()->prepare('finance-prepare','3')['receipt']);
        $this->refused(fn()=>$this->fence()->extendFinance('other','2'),'AUTHORITY_FINANCE_REQUEST_CONFLICT');
        $this->refused(fn()=>$this->fence()->extendFinance('finance-fixture','3'),'AUTHORITY_FINANCE_REQUEST_CONFLICT');
        foreach(['global_writer_coverage_complete','global_drain_complete','target_authority_active','migration_gate_open']as$key)$this->assertFalse($prepared[$key]);
    }
    public function test_old_v1_and_second_inventory_prepared_receipt_hashes_recover_under_new_declaration_without_extension():void
    {
        foreach([1,2]as$version){
            foreach(['tx_authority_prepare_receipt','tx_authority_finance_upgrade','tx_authority_inventory_upgrade','tx_authority_writer_fence']as$table)DB::table($table)->delete();
            config(['authority_writer_fence'=>['enabled'=>true,'worker_epoch'=>'1','worker_role'=>'legacy']]);$this->fence()->initialize();
            if($version===2){$this->fence()->expand('historical-inventory','1');$this->v2Worker();}
            $prepared=$this->fence()->prepare('old-prepared-'.$version,(string)$version);$before=$this->history();
            Schema::drop('tx_authority_finance_upgrade');$migration=require database_path('migrations/2026_10_14_000003_extend_finance_authority_inventory.php');$migration->up();
            $this->newWorker();$recover=$this->fence()->recover('old-prepared-'.$version,(string)$version);
            $this->assertSame($prepared['receipt'],$recover['receipt']);$this->assertSame($prepared['state_hash'],$recover['state_hash']);
            $this->refused(fn()=>$this->fence()->extendFinance('cannot-reopen','2'),'AUTHORITY_FINANCE_EXTEND_REFUSED');
            $this->assertSame($before,$this->history());
        }
    }
    public function test_actual_order_http_create_cancel_keeps_money_coupon_idempotency_and_admits_before_sql_mutation():void
    {
        $this->finance();$user=$this->user(['balance'=>300]);$plan=$this->plan();$coupon=$this->coupon();Sanctum::actingAs($user);
        $response=$this->admissionFirst(fn()=>$this->createOrder($plan,$coupon)->assertCreated());$trade=$response->json('data.trade_no');
        $this->assertSame(0,(int)$user->fresh()->balance);$this->assertSame(4,(int)$coupon->fresh()->limit_use);
        $this->createOrder($plan,$coupon)->assertCreated()->assertJsonPath('data.trade_no',$trade);$this->assertSame(1,Order::count());
        $this->admissionFirst(fn()=>$this->postJson('/txapi/orders/'.$trade.'/cancel')->assertOk());
        $this->assertSame(300,(int)$user->fresh()->balance);$this->assertSame(5,(int)$coupon->fresh()->limit_use);
        $this->postJson('/txapi/orders/'.$trade.'/cancel')->assertStatus(409);$this->assertSame(300,(int)$user->fresh()->balance);
    }
    public function test_verified_wallet_webhook_actual_http_creation_credits_once_and_bad_signature_cannot_credit():void
    {
        $this->finance();$user=$this->user();$payment=$this->payment();Sanctum::actingAs($user);
        $response=$this->admissionFirst(fn()=>$this->createRecharge($payment)->assertCreated());$trade=$response->json('data.trade_no');
        $this->createRecharge($payment)->assertCreated()->assertJsonPath('data.trade_no',$trade);$this->assertSame(0,(int)$user->fresh()->balance);
        $signed=$this->signature($trade,'10.00','finance-wallet-callback');$wrong=$signed;$wrong['sign']='wrong';
        $this->post($this->webhook($payment),$wrong)->assertStatus(422);$this->assertSame(0,(int)$user->fresh()->balance);
        $this->admissionFirst(fn()=>$this->post($this->webhook($payment),$signed)->assertOk()->assertSeeText('success'));
        $this->post($this->webhook($payment),$signed)->assertOk();$this->assertSame(1000,(int)$user->fresh()->balance);
        $this->assertSame(1,WalletRecharge::where('status',WalletRecharge::STATUS_PAID)->count());
    }
    public function test_order_checkout_verified_paid_and_original_queue_fulfillment_delegate_all_admissions():void
    {
        $this->finance();$user=$this->user();$plan=$this->plan();$payment=$this->payment();Sanctum::actingAs($user);
        $trade=$this->createOrder($plan)->assertCreated()->json('data.trade_no');
        $this->admissionFirst(fn()=>$this->postJson('/txapi/orders/'.$trade.'/checkout',['method'=>$payment->id])->assertOk());
        $signed=$this->signature($trade,'10.00','finance-order-callback');
        $this->admissionFirst(fn()=>$this->post($this->webhook($payment),$signed)->assertOk()->assertSeeText('success'));
        $this->assertSame(Order::STATUS_PROCESSING,(int)Order::where('trade_no',$trade)->first()->status);
        Bus::assertDispatched(OrderHandleJob::class);
        $this->admissionFirst(fn()=>(new OrderHandleJob($trade))->handle());$this->assertSame(Order::STATUS_COMPLETED,(int)Order::where('trade_no',$trade)->first()->status);
        $this->assertSame($plan->id,(int)$user->fresh()->plan_id);$before=$this->business();
        (new OrderHandleJob($trade))->handle();$this->post($this->webhook($payment),$signed)->assertOk();$this->assertSame($before,$this->business());
    }
    public function test_native_commission_transfer_and_gift_redeem_use_original_outer_transactions():void
    {
        $this->finance();$user=$this->user(['commission_balance'=>150]);Sanctum::actingAs($user);$code=$this->gift();
        $this->admissionFirst(fn()=>$this->postJson('/txapi/billing/commission-transfer',['transfer_amount'=>50])->assertOk());
        $this->assertSame(50,(int)$user->fresh()->balance);$this->assertSame(100,(int)$user->fresh()->commission_balance);
        $this->admissionFirst(fn()=>$this->postJson('/txapi/gift-cards/redeem',['code'=>$code->code])->assertOk());
        $this->assertSame(250,(int)$user->fresh()->balance);$this->assertSame(1,(int)$code->fresh()->usage_count);
        $this->postJson('/txapi/gift-cards/redeem',['code'=>$code->code])->assertStatus(409);$this->assertSame(250,(int)$user->fresh()->balance);
    }
    public function test_prepared4_blocks_all_nine_writers_and_preserves_native_safe_failure_diagnostics():void
    {
        $this->finance();$user=$this->user(['balance'=>400,'commission_balance'=>150]);$plan=$this->plan();$payment=$this->payment();$code=$this->gift();Sanctum::actingAs($user);
        $pending=$this->order($user,$plan,['balance_amount'=>100]);$processing=$this->order($user,$plan,['status'=>Order::STATUS_PROCESSING]);
        $recharge=app(WalletRechargeService::class)->create($user->id,1000,$payment->id,(string)Str::uuid());$before=$this->business();
        $this->fence()->prepare('stop-finance','3');$this->newWorker('4');
        $this->createOrder($plan)->assertStatus(409);$this->postJson('/txapi/orders/'.$pending->trade_no.'/checkout',['method'=>$payment->id])->assertStatus(409);
        $this->postJson('/txapi/orders/'.$pending->trade_no.'/cancel')->assertStatus(409);
        $this->createRecharge($payment)->assertStatus(409);
        $this->post($this->webhook($payment),$this->signature($recharge->trade_no,'10.00','blocked-recharge'))->assertStatus(500)->assertSeeText('fail');
        $this->assertFalse((new OrderService($pending))->paid('blocked-paid'));
        $this->refused(fn()=>(new OrderHandleJob($processing->trade_no))->handle(),'AUTHORITY_FENCE_PREPARED');
        (new OrderHandleJob($processing->trade_no))->failed(new AuthorityFenceException('AUTHORITY_FENCE_PREPARED'));
        $this->postJson('/txapi/billing/commission-transfer',['transfer_amount'=>50])->assertStatus(409);
        $this->postJson('/txapi/gift-cards/redeem',['code'=>$code->code])->assertStatus(409);$this->assertSame($before,$this->business());
        $messages=array_column($this->log->getRecords(),'message');
        foreach(['AUTHORITY_ORDER_PAID_FENCE_REJECTED','AUTHORITY_ORDER_CANCEL_FENCE_REJECTED','AUTHORITY_PAYMENT_SETTLEMENT_FENCE_REJECTED',
            'AUTHORITY_ORDER_FULFILLMENT_FENCE_REJECTED','AUTHORITY_ORDER_FULFILLMENT_FENCE_RETRIES_EXHAUSTED']as$message)$this->assertContains($message,$messages);
        $safe=json_encode($this->log->getRecords());foreach([$user->email,$user->token,$user->uuid,'finance-local-merchant-secret',$pending->trade_no,'blocked-paid']as$secret)$this->assertStringNotContainsString($secret,$safe);
    }
    public function test_default_off_missing_finance_schema_preserves_native_money_and_no_authority_queries():void
    {
        config(['authority_writer_fence'=>['enabled'=>false,'worker_epoch'=>'bad','worker_role'=>'bad'],'core_shadow.isolated_copy'=>false]);$this->app['env']='production';
        foreach(['tx_authority_finance_upgrade','tx_authority_inventory_upgrade','tx_authority_prepare_receipt','tx_authority_writer_fence']as$table)Schema::drop($table);
        $user=$this->user(['commission_balance'=>150]);$plan=$this->plan();$payment=$this->payment();$gift=$this->gift();Sanctum::actingAs($user);
        DB::enableQueryLog();DB::flushQueryLog();$trade=$this->createOrder($plan)->assertCreated()->json('data.trade_no');
        $this->postJson('/txapi/orders/'.$trade.'/checkout',['method'=>$payment->id])->assertOk();
        $this->postJson('/txapi/orders/'.$trade.'/cancel')->assertOk();
        $recharge=$this->createRecharge($payment)->assertCreated()->json('data.trade_no');
        $this->post($this->webhook($payment),$this->signature($recharge,'10.00','native-recharge'))->assertOk();
        $paid=$this->order($user,$plan,['payment_id'=>$payment->id]);$this->post($this->webhook($payment),$this->signature($paid->trade_no,'10.00','native-paid'))->assertOk();
        (new OrderHandleJob($paid->trade_no))->handle();$this->postJson('/txapi/billing/commission-transfer',['transfer_amount'=>50])->assertOk();
        $this->postJson('/txapi/gift-cards/redeem',['code'=>$gift->code])->assertOk();$queries=DB::getQueryLog();DB::disableQueryLog();
        foreach($queries as$query)$this->assertStringNotContainsString('tx_authority_',$query['query']);
    }
    public function test_old_worker_wrong_inventory_and_closed_history_refuse_money_before_change():void
    {
        $this->finance();$user=$this->user(['commission_balance'=>150]);$plan=$this->plan();Sanctum::actingAs($user);$before=$this->business();
        $this->v2Worker();$this->postJson('/txapi/billing/commission-transfer',['transfer_amount'=>50])->assertStatus(409);
        $this->newWorker();DB::table('tx_authority_writer_fence')->update(['inventory_hash'=>str_repeat('a',64)]);
        $this->createOrder($plan)->assertStatus(409);$this->assertSame($before,$this->business());
    }
    public function test_finance_upgrade_storage_failure_and_parent_hash_corruption_are_closed():void
    {
        $this->v2();$before=$this->history();$fail=true;
        DB::listen(function($query)use(&$fail):void{if($fail&&preg_match('/^insert.*tx_authority_finance_upgrade/i',$query->sql))throw new \RuntimeException('fixture-secret-failure');});
        $this->refused(fn()=>$this->fence()->extendFinance('rollback-finance','2'),'AUTHORITY_FENCE_STORAGE_OR_EVIDENCE_FAILED');
        $this->assertSame($before,$this->history());$this->assertSame('inconclusive_no_committed_finance',$this->fence()->recoverFinance('rollback-finance','2')['recovery_status']);
        $fail=false;$this->fence()->extendFinance('finance-fixture','2');$this->newWorker();$row=(array)DB::table('tx_authority_finance_upgrade')->first();
        $row['parent_inventory_upgrade_hash']=str_repeat('e',64);$payload=$row;unset($payload['content_hash']);
        foreach(['expected_epoch','expanded_epoch','prior_updated_at','captured_at']as$key)$payload[$key]=(string)$payload[$key];
        $row['content_hash']=$this->hash($payload);DB::table('tx_authority_finance_upgrade')->update($row);
        $this->refused(fn()=>$this->fence()->status(),'AUTHORITY_FINANCE_EVIDENCE_INVALID');
    }
    public function test_recovery_cli_and_closed_schema_replay_are_readonly_immutable_and_no_reopen():void
    {
        $this->v2();$this->assertSame(0,Artisan::call('core:authority-fence',['operation'=>'extend-finance','--key'=>'finance-fixture','--expected-epoch'=>'2','--isolated-copy'=>true]));$this->newWorker();
        $this->fence()->prepare('closed-prepare','3');$before=$this->history();
        DB::enableQueryLog();DB::flushQueryLog();$this->fence()->status();$this->fence()->recover('closed-prepare','3');$this->fence()->recoverFinance('finance-fixture','2');
        $queries=DB::getQueryLog();DB::disableQueryLog();$this->assertCount(18,$queries);foreach($queries as$query)$this->assertMatchesRegularExpression('/\Aselect\b/i',$query['query']);
        $this->assertSame($before,$this->history());$receipt=AuthorityFinanceUpgrade::findOrFail('finance-fixture');
        $this->refused(fn()=>$receipt->update(['expected_epoch'=>3]),'AUTHORITY_FINANCE_RECEIPT_IMMUTABLE');$this->refused(fn()=>$receipt->delete(),'AUTHORITY_FINANCE_RECEIPT_IMMUTABLE');
        $migration=require database_path('migrations/2026_10_14_000003_extend_finance_authority_inventory.php');$migration->up();$this->assertSame($before,$this->history());
        try{$migration->down();$this->fail('Expected populated refusal');}catch(\RuntimeException $e){$this->assertSame('AUTHORITY_FINANCE_POPULATED_ROLLBACK_REFUSED',$e->getMessage());}
    }
    public function test_prepare_between_checkout_binding_commit_and_external_provider_preserves_partial_native_boundary():void
    {
        $this->finance();$user=$this->user();$plan=$this->plan();$payment=$this->payment();$order=$this->order($user,$plan);$done=false;
        Event::listen(TransactionCommitted::class,function($event)use(&$done):void{
            if(!$done&&DB::transactionLevel()===0){$done=true;$this->fence()->prepare('checkout-window','3');}
        });
        $result=app(OrderCheckout::class)->start($user->id,$order->trade_no,$payment->id);
        $this->assertTrue($done);$this->assertSame(1,$result['type']);$this->assertSame($payment->id,(int)$order->fresh()->payment_id);
        $this->assertSame(Order::STATUS_PENDING,(int)$order->fresh()->status);$this->assertSame('prepared',$this->fence()->status()['phase']);
    }
    public function test_free_checkout_prepare_window_preserves_native_conflict_without_auto_compensation():void
    {
        $this->finance();$user=$this->user();$plan=$this->plan();$order=$this->order($user,$plan,['total_amount'=>0]);$done=false;
        Event::listen(TransactionCommitted::class,function($event)use(&$done):void{
            if(!$done&&DB::transactionLevel()===0){$done=true;$this->fence()->prepare('free-window','3');}
        });
        try{app(OrderCheckout::class)->start($user->id,$order->trade_no);$this->fail('Expected native paid conflict');}
        catch(\App\Exceptions\ApiException $e){$this->assertSame(409,(int)$e->getCode());}
        $this->assertTrue($done);$this->assertSame(Order::STATUS_PENDING,(int)$order->fresh()->status);
        $this->assertSame('prepared',$this->fence()->status()['phase']);
    }

    public function test_real_mysql_admitted_order_cancel_commits_before_prepare_and_refunds_once():void{$this->ordering('order','commit');}
    public function test_real_mysql_admitted_order_cancel_rollback_unblocks_prepare_without_refund():void{$this->ordering('order','rollback');}
    public function test_real_mysql_killed_admitted_cancel_rolls_back_money_before_prepare():void{$this->ordering('order','kill');}
    public function test_real_mysql_verified_recharge_commit_waits_before_prepare_and_credits_once():void{$this->ordering('recharge','commit');}
    public function test_real_mysql_killed_recharge_rolls_back_credit_then_prepare_commits():void{$this->ordering('recharge','kill');}
    public function test_real_mysql_finance_extension_killed_before_commit_recovers_exact_retry():void{$this->killControl('finance',false);}
    public function test_real_mysql_finance_extension_committed_output_loss_recovers_same_receipt():void{$this->killControl('finance',true);}
    public function test_real_mysql_prepare4_killed_before_commit_retains_open3_and_exact_retry():void{$this->killControl('prepare',false);}
    public function test_real_mysql_prepare4_committed_output_loss_recovers_same_receipt():void{$this->killControl('prepare',true);}

    private function ordering(string $kind,string $mode):void
    {
        $this->requireMysql();$this->finance();$user=$this->user();$plan=$this->plan();$payment=$this->payment();$order=$this->order($user,$plan,['balance_amount'=>200]);
        $recharge=app(WalletRechargeService::class)->create($user->id,1000,$payment->id,(string)Str::uuid());$name=$this->connection();DB::disconnect($name);
        [$writer,$socket]=$this->spawn(function($socket)use($kind,$user,$order,$recharge,$payment):void{
            DB::listen(function($query)use($socket):void{if(preg_match('/^update.*tx_user/i',$query->sql)){
                $this->send($socket,['stage'=>'money_write_pending']);$message=$this->receive($socket);
                if($message['action']==='rollback')throw new \RuntimeException('synthetic-money-rollback');
            }});
            $result=$kind==='order'?(new OrderService($order))->cancel():app(WalletRechargeService::class)->settleVerified($recharge->trade_no,$payment->id,'locked-callback',1000);
            $this->send($socket,['result'=>$result]);
        });$controller=null;$controllerSocket=null;
        try{
            $this->assertSame('money_write_pending',$this->receive($socket)['stage']);
            [$controller,$controllerSocket]=$this->spawn(function($socket):void{
                $this->send($socket,['stage'=>'prepare_attempt']);$this->send($socket,['result'=>$this->fence()->prepare('ordered-money','3')]);
            });$this->assertSame('prepare_attempt',$this->receive($controllerSocket)['stage']);
            $read=[$controllerSocket];$write=null;$except=null;$this->assertSame(0,stream_select($read,$write,$except,0,150000));
            if($mode==='kill'){posix_kill($writer,SIGKILL);pcntl_waitpid($writer,$status);$writer=null;}
            else{$this->send($socket,['action'=>$mode]);$this->assertSame($mode==='commit',$this->receive($socket)['result']);pcntl_waitpid($writer,$status);$writer=null;}
            $prepared=$this->receive($controllerSocket);$this->assertArrayHasKey('result',$prepared);$this->assertSame('4',$prepared['result']['authority_epoch']);
            pcntl_waitpid($controller,$status);$controller=null;
            $this->assertSame($mode==='commit'?($kind==='order'?200:1000):0,(int)$user->fresh()->balance);
            $this->assertSame($kind==='order'&&$mode==='commit'?Order::STATUS_CANCELLED:Order::STATUS_PENDING,(int)$order->fresh()->status);
            if($kind==='recharge')$this->assertSame($mode==='commit'?WalletRecharge::STATUS_PAID:WalletRecharge::STATUS_PENDING,(int)$recharge->fresh()->status);
        }finally{foreach([$writer,$controller]as$pid)if($pid){posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);}fclose($socket);if(is_resource($controllerSocket))fclose($controllerSocket);DB::purge($name);}
    }
    private function killControl(string $operation,bool $afterCommit):void
    {
        $this->requireMysql();if($operation==='prepare')$this->finance();else$this->v2();$name=$this->connection();DB::disconnect($name);
        [$pid,$socket]=$this->spawn(function($socket)use($operation,$afterCommit):void{
            if(!$afterCommit)DB::listen(function($query)use($socket,$operation):void{
                $table=$operation==='finance'?'tx_authority_finance_upgrade':'tx_authority_prepare_receipt';
                if(preg_match('/^insert/i',$query->sql)&&str_contains($query->sql,$table)){$this->send($socket,['stage'=>'before_commit']);$this->receive($socket);}
            });$result=$operation==='finance'?$this->fence()->extendFinance('killed-finance','2'):$this->fence()->prepare('killed-finance','3');
            $this->send($socket,['stage'=>'after_commit','hash'=>$result[$operation==='finance'?'finance_receipt':'receipt']['content_hash']]);$this->receive($socket);
        });
        try{
            $message=$this->receive($socket);$this->assertSame($afterCommit?'after_commit':'before_commit',$message['stage']);posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);$pid=null;
            $recover=$operation==='finance'?$this->fence()->recoverFinance('killed-finance','2'):$this->fence()->recover('killed-finance','3');
            $this->assertSame(($afterCommit?'committed_':'inconclusive_no_committed_').($operation==='finance'?'finance':'prepare'),$recover['recovery_status']);
            $retry=$operation==='finance'?$this->fence()->extendFinance('killed-finance','2'):$this->fence()->prepare('killed-finance','3');
            $this->assertSame($afterCommit,$retry['replayed']);if($afterCommit)$this->assertSame($message['hash'],$retry[$operation==='finance'?'finance_receipt':'receipt']['content_hash']);
        }finally{if($pid){posix_kill($pid,SIGKILL);pcntl_waitpid($pid,$status);}fclose($socket);DB::purge($name);}
    }
    private function spawn(callable $task):array
    {
        $pair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);$this->assertIsArray($pair);$pid=pcntl_fork();$this->assertNotSame(-1,$pid);
        if($pid===0){fclose($pair[0]);DB::purge($this->connection());try{$task($pair[1]);}catch(\Throwable $e){$this->send($pair[1],['child_error'=>get_class($e),'code'=>$e instanceof AuthorityFenceException?$e->getMessage():'fixture_failure']);}fclose($pair[1]);exit(0);}fclose($pair[1]);return[$pid,$pair[0]];
    }
    private function send($socket,array $value):void{fwrite($socket,json_encode($value,JSON_THROW_ON_ERROR)."\n");fflush($socket);}
    private function receive($socket):array{stream_set_timeout($socket,12);$line=fgets($socket);$this->assertIsString($line);return json_decode($line,true,flags:JSON_THROW_ON_ERROR);}
    private function requireMysql():void{if(!$this->mysql()||!function_exists('pcntl_fork')||!function_exists('posix_kill'))$this->markTestSkipped('Requires independent MySQL processes and SIGKILL.');}
    private function admissionFirst(callable $operation):mixed
    {
        DB::enableQueryLog();DB::flushQueryLog();$result=$operation();$queries=DB::getQueryLog();DB::disableQueryLog();$authority=null;$mutation=null;
        foreach($queries as$i=>$q){if($authority===null&&str_contains($q['query'],'tx_authority_writer_fence'))$authority=$i;
            if($mutation===null&&preg_match('/\A(?:insert|update|delete)\b/i',$q['query'])&&!str_contains($q['query'],'tx_authority_'))$mutation=$i;}
        $this->assertIsInt($authority);$this->assertIsInt($mutation);$this->assertLessThan($mutation,$authority);return$result;
    }
    private function v2():void{config(['authority_writer_fence'=>['enabled'=>true,'worker_epoch'=>'1','worker_role'=>'legacy']]);$this->fence()->initialize();$this->fence()->expand('inventory-fixture','1');$this->v2Worker();}
    private function finance():void{$this->v2();$this->fence()->extendFinance('finance-fixture','2');$this->newWorker();}
    private function v2Worker():void{config(['authority_writer_fence'=>['enabled'=>true,'worker_epoch'=>'2','worker_role'=>'legacy','worker_scope'=>AuthorityInventory::SCOPE,'worker_version'=>AuthorityInventory::VERSION]]);}
    private function newWorker(string $epoch='3'):void{config(['authority_writer_fence'=>['enabled'=>true,'worker_epoch'=>$epoch,'worker_role'=>'legacy','worker_scope'=>AuthorityFinanceInventory::SCOPE,'worker_version'=>AuthorityFinanceInventory::VERSION]]);}
    private function fence():AuthorityWriterFence{return app(AuthorityWriterFence::class);}
    private function user(array $attrs=[]):User{return User::withoutEvents(fn()=>User::create(array_replace(['email'=>bin2hex(random_bytes(6)).'@example.test','password'=>'synthetic-no-login','uuid'=>(string)Str::uuid(),'token'=>bin2hex(random_bytes(16)),'u'=>0,'d'=>0,'balance'=>0,'commission_balance'=>0,'transfer_enable'=>0],$attrs)));}
    private function plan():Plan{return Plan::create(['name'=>'Finance Plan','group_id'=>1,'transfer_enable'=>1,'prices'=>['monthly'=>10],'reset_traffic_method'=>1,'show'=>true,'sell'=>true,'renew'=>true,'capacity_limit'=>null]);}
    private function payment():Payment{return Payment::create(['uuid'=>bin2hex(random_bytes(16)),'payment'=>'EPay','name'=>'Finance EPay','enable'=>true,'handling_fee_fixed'=>0,'handling_fee_percent'=>0,'config'=>['pid'=>'finance-merchant','key'=>'finance-local-merchant-secret','url'=>'https://payment.invalid']]);}
    private function coupon():Coupon{return Coupon::create(['code'=>'FINANCECOUPON','name'=>'Finance Coupon','type'=>1,'value'=>200,'show'=>true,'limit_use'=>5]);}
    private function gift():GiftCardCode{$template=GiftCardTemplate::create(['name'=>'Finance Gift','type'=>GiftCardTemplate::TYPE_GENERAL,'status'=>true,'admin_id'=>1,'rewards'=>['balance'=>200]]);return GiftCardCode::create(['template_id'=>$template->id,'code'=>'FINANCEGIFT','status'=>GiftCardCode::STATUS_UNUSED,'max_usage'=>1,'usage_count'=>0]);}
    private function order(User $user,Plan $plan,array $extra=[]):Order{return Order::create(array_replace(['user_id'=>$user->id,'plan_id'=>$plan->id,'trade_no'=>'finance-order-'.bin2hex(random_bytes(6)),'period'=>'monthly','type'=>Order::TYPE_NEW_PURCHASE,'status'=>Order::STATUS_PENDING,'total_amount'=>1000],$extra));}
    private function createOrder(Plan $plan,?Coupon $coupon=null):\Illuminate\Testing\TestResponse{return$this->postJson('/txapi/orders',array_filter(['plan_id'=>$plan->id,'period'=>'monthly','coupon_code'=>$coupon?->code],fn($v)=>$v!==null),['Idempotency-Key'=>'f2b01300-9d18-420d-a0d6-f213147d0001']);}
    private function createRecharge(Payment $payment):\Illuminate\Testing\TestResponse{return$this->postJson('/txapi/billing/recharges',['amount_minor'=>1000,'payment_method_id'=>$payment->id],['Idempotency-Key'=>'f2b01300-9d18-420d-a0d6-f213147d0002']);}
    private function webhook(Payment $payment):string{return'/txapi/payment/webhook/EPay/'.$payment->uuid;}
    private function signature(string $trade,string $money,string $callback):array{$params=['pid'=>'finance-merchant','out_trade_no'=>$trade,'trade_no'=>$callback,'money'=>$money,'trade_status'=>'TRADE_SUCCESS'];ksort($params);$params['sign']=md5(stripslashes(urldecode(http_build_query($params))).'finance-local-merchant-secret');$params['sign_type']='MD5';return$params;}
    private function business():array{$out=[];foreach(['tx_user','tx_order','tx_wallet_recharge','tx_coupon','tx_gift_card_code','tx_gift_card_usage']as$table)$out[$table]=DB::table($table)->orderBy('id')->get()->toJson();return$out;}
    private function history():array{return['state'=>DB::table('tx_authority_writer_fence')->get()->toJson(),'prepare'=>DB::table('tx_authority_prepare_receipt')->get()->toJson(),'inventory'=>DB::table('tx_authority_inventory_upgrade')->get()->toJson(),'finance'=>DB::table('tx_authority_finance_upgrade')->get()->toJson()];}
    private function hash(array $value):string{ksort($value,SORT_STRING);return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
    private function refused(callable $call,string $code):void{try{$call();$this->fail('Expected refusal');}catch(AuthorityFenceException $e){$this->assertSame($code,$e->getMessage());$this->assertNull($e->getPrevious());}}
    private function mysql():bool{return getenv('CORE_FINANCE_FENCE_TEST_MYSQL')==='1';}
    private function connection():string{return$this->mysql()?'finance_fence_mysql':'finance_fence_sqlite';}
    private function clearMysql():void{if(DB::connection()->getDatabaseName()!=='txboard_finance_fence_test')throw new \RuntimeException('Finance fence requires dedicated fixture DB.');Schema::disableForeignKeyConstraints();try{foreach(Schema::getTableListing()as$table)Schema::drop($table);}finally{Schema::enableForeignKeyConstraints();}}
}
