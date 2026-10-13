<?php

namespace Tests\Feature\Subscription;

use App\Models\Plan;
use App\Models\User;
use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacySubscriptionSnapshot;
use App\Services\Subscription\LegacyUserMutationCapture;
use App\Services\Subscription\SubscriptionShadowBackfill;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegacyBulkBanCaptureTest extends TestCase
{
    private int $counter=0;
    private Plan $plan;
    private User $admin;
    protected function setUp(): void
    {
        parent::setUp(); $name=$this->connection();
        config(['database.default'=>$name,'database.connections.'.$name=>$this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'),['database'=>':memory:'])]);
        DB::purge($name); if ($this->mysql()) $this->clearMysql();
        $this->assertSame(0,Artisan::call('migrate',['--force'=>true])); Bus::fake();
        config(['core_shadow.isolated_copy'=>true,'legacy_capture_shadow.enabled'=>true,
            'legacy_capture_shadow.run_key'=>'bulk-ban-fixture','legacy_capture_shadow.billing_timezone'=>'UTC',
            'legacy_capture_shadow.currency'=>'USD','legacy_bulk_ban_capture.enabled'=>true,'legacy_observer_capture.enabled'=>false]);
        admin_setting(['secure_path'=>'bulk_ban_admin']);
        $this->plan=Plan::create(['name'=>'Ban plan','group_id'=>1,'transfer_enable'=>1,'prices'=>['monthly'=>1],'reset_traffic_method'=>0]);
        $this->admin=$this->user(['is_admin'=>true]); Sanctum::actingAs($this->admin);
    }
    protected function tearDown(): void
    { if ($this->mysql()) $this->clearMysql(); DB::purge($this->connection()); parent::tearDown(); }

    public function test_actual_selected_ban_endpoints_admin_owner_and_sessions_share_original_transaction(): void
    {
        $one=$this->user(['u'=>9007199254740993]); $two=$this->user(); $outside=$this->user();
        foreach ([$one,$two] as $user) { $this->backfill($user); $user->createToken('synthetic-session'); }
        $outside->createToken('synthetic-unrelated'); $before=[$this->source($one),$this->source($two)]; $owned=$this->ownedState();
        $saves=0; Event::listen('eloquent.saving: '.User::class,function () use (&$saves): void { ++$saves; });
        $this->ban([$two->id,$one->id])->assertOk()->assertJsonPath('data.updated',2);
        $this->assertSame(0,$saves); $this->assertSame($owned,$this->ownedState());
        $this->assertSame(0,$one->tokens()->count()); $this->assertSame(0,$two->tokens()->count()); $this->assertSame(1,$outside->tokens()->count());
        $this->assertFalse((bool)$outside->fresh()->banned); $rows=DB::table('tx_legacy_capture_shadow_event')->orderBy('id')->get(); $this->assertCount(2,$rows);
        foreach ([$one,$two] as $index=>$user) {
            $after=$before[$index]; $after['banned']=true; $this->assertSame($after,$this->source($user));
            $this->assertSame($before[$index],$this->decodedSource($rows[$index]->before_snapshot));
            $this->assertSame($after,$this->decodedSource($rows[$index]->after_snapshot));
            $this->assertSame('source_changed',DB::table('tx_legacy_subscription_map')->where('legacy_user_id',$user->id)->value('state'));
            $page=$this->capture()->verify($user->id); $this->assertTrue($page['page_ok']);
            $this->assertFalse($page['global_coverage_complete']); $this->assertFalse($page['migration_gate_open']);
            $this->assertSame(['admin.user.bulk-ban','bulk-ban-capture/v1','admin_user',(string)$this->admin->id],
                array_map(fn ($key)=>$page['events'][0][$key],['source_kind','source_version','actor_domain','actor_id']));
            foreach ([$user->email,$user->password,$user->uuid,$user->token,'synthetic-session'] as $secret)
                $this->assertStringNotContainsString($secret,json_encode([$rows[$index],$page]));
        }
    }

    public function test_already_banned_native_count_and_repeat_session_deletion_do_not_invent_endpoint_events(): void
    {
        $one=$this->user(); $two=$this->user(['banned'=>true]); $this->ban([$one->id,$two->id])->assertOk()->assertJsonPath('data.updated',2);
        $this->assertSame(1,DB::table('tx_legacy_capture_shadow_event')->count()); $history=$this->history();
        foreach ([$one,$two] as $user) $user->createToken('new-after-ban');
        $this->ban([$one->id,$two->id])->assertOk()->assertJsonPath('data.updated',2);
        $this->assertSame($history,$this->history()); $this->assertSame(0,$one->tokens()->count()); $this->assertSame(0,$two->tokens()->count());
    }

    public function test_default_off_production_retains_original_ban_and_session_sql_without_capture(): void
    {
        $user=$this->user(); $user->createToken('old');
        config(['legacy_bulk_ban_capture.enabled'=>false,'legacy_capture_shadow.enabled'=>'invalid','core_shadow.isolated_copy'=>false]); $this->app['env']='production';
        foreach (['tx_legacy_money_shadow_posting','tx_legacy_money_shadow_step','tx_legacy_money_shadow_checkpoint',
            'tx_legacy_capture_shadow_event','tx_legacy_capture_shadow_stream'] as $table) Schema::drop($table);
        DB::enableQueryLog(); DB::flushQueryLog(); $this->ban([$user->id])->assertOk()->assertJsonPath('data.updated',1);
        $this->assertSame([],array_values(array_filter(DB::getQueryLog(),fn ($q)=>preg_match('/tx_legacy_capture_shadow|tx_legacy_subscription_map/',$q['query']))));
        $this->assertTrue((bool)$user->fresh()->banned); $this->assertSame(0,$user->tokens()->count());
    }

    public function test_native_role_path_protected_missing_and_duplicate_selections_refuse_without_mutation(): void
    {
        $one=$this->user(); $staff=$this->user(['is_staff'=>true]); $one->createToken('unchanged'); $before=$this->state();
        Sanctum::actingAs($one); $this->ban([$one->id])->assertStatus(403); Sanctum::actingAs($this->admin);
        $this->postJson('/txapi/admin/wrong/users/ban',['scope'=>'selected','user_ids'=>[$one->id]])->assertStatus(404);
        foreach ([[$one->id,$staff->id],[$one->id,$this->admin->id],[$one->id,2147483647],[$one->id,$one->id]] as $ids) $this->ban($ids)->assertStatus(422);
        $this->ban(range(1,501))->assertStatus(422);
        $this->assertSame($before,$this->state());
    }

    public function test_strict_isolation_and_context_failure_preserve_every_user_and_session(): void
    {
        $user=$this->user(); $user->createToken('unchanged'); $before=$this->state();
        foreach (['1',1,null] as $invalid) { config(['legacy_bulk_ban_capture.enabled'=>$invalid]); $this->ban([$user->id])->assertStatus(409); }
        config(['legacy_bulk_ban_capture.enabled'=>true,'legacy_capture_shadow.enabled'=>false]); $this->ban([$user->id])->assertStatus(409);
        config(['legacy_capture_shadow.enabled'=>true,'core_shadow.isolated_copy'=>false]); $this->ban([$user->id])->assertStatus(409);
        config(['core_shadow.isolated_copy'=>true]); $this->app['env']='production'; $this->ban([$user->id])->assertStatus(409);
        $this->app['env']='testing'; config(['legacy_capture_shadow.currency'=>'JPY']); $this->ban([$user->id])->assertStatus(409);
        $this->assertSame($before,$this->state());
    }

    public function test_empty_filtered_result_preserves_native_early_success_without_capture_context(): void
    {
        config(['legacy_bulk_ban_capture.enabled'=>'invalid']); DB::enableQueryLog(); DB::flushQueryLog();
        $this->filtered([['id'=>'email','value'=>'missing-user']])->assertOk()->assertJsonPath('data.updated',0);
        $this->assertSame([],array_values(array_filter(DB::getQueryLog(),fn ($q)=>preg_match('/tx_legacy_capture_shadow|tx_legacy_subscription_map/',$q['query']))));
    }

    public function test_filtered_ids_keep_native_pretransaction_selection_without_rechecking_old_qualification(): void
    {
        $user=$this->user(); $user->createToken('old'); $active=true;
        DB::listen(function ($q) use (&$active,$user): void {
            if ($active && preg_match('/^select.*tx_user.*plan_id.*limit 502/i',$q->sql)) {
                $active=false; DB::table('tx_user')->where('id',$user->id)->update(['plan_id'=>null]);
            }
        });
        // Exclude the Admin from the initial filter while retaining a Plan for the User.
        $this->admin->updateQuietly(['plan_id'=>null]);
        $this->filtered([['id'=>'plan_id','value'=>'eq:'.$this->plan->id]])->assertOk()->assertJsonPath('data.updated',1);
        $this->assertFalse($active); $this->assertNull($user->fresh()->plan_id); $this->assertTrue((bool)$user->fresh()->banned);
        $event=DB::table('tx_legacy_capture_shadow_event')->first(); $this->assertNull(json_decode($event->before_snapshot,true)['plan_id']);
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']); $this->assertSame(0,$user->tokens()->count());
    }

    public function test_second_user_source_gap_rolls_back_all_bans_sessions_and_first_event(): void
    {
        $one=$this->user(); $two=$this->user();
        foreach ([$one,$two] as $user) {
            $this->postJson('/txapi/admin/bulk_ban_admin/users/'.$user->id.'/update',['speed_limit'=>1])->assertOk();
            $user->createToken('retain-after-gap');
        }
        DB::table('tx_user')->where('id',$two->id)->update(['u'=>99]);
        $before=$this->state(); $this->ban([$one->id,$two->id])->assertStatus(409); $this->assertSame($before,$this->state());
        $this->assertTrue($this->capture()->verify($one->id)['page_ok']);
    }

    public function test_sql_session_delete_failure_rolls_back_native_ban_with_nonsecret_capture_error(): void
    {
        $user=$this->user(); $user->createToken('retain'); $before=$this->state(); $active=true;
        DB::listen(function ($q) use (&$active): void {
            if ($active && preg_match('/^delete.*personal_access_tokens/i',$q->sql)) throw new \RuntimeException('private-session-delete-detail');
        });
        try { $response=$this->ban([$user->id])->assertStatus(409); } finally { $active=false; }
        $this->assertStringNotContainsString('private-session-delete-detail',$response->getContent()); $this->assertSame($before,$this->state());
    }

    public function test_second_capture_storage_failure_restores_all_sessions_and_mapping_state(): void
    {
        $one=$this->user(); $two=$this->user(); foreach ([$one,$two] as $user) { $this->backfill($user); $user->createToken('retain'); }
        $before=$this->state(); $active=true; $inserts=0;
        DB::listen(function ($q) use (&$active,&$inserts): void {
            if ($active && preg_match('/^insert.*tx_legacy_capture_shadow_event/i',$q->sql) && ++$inserts === 2)
                throw new \RuntimeException('private-ban-storage-detail');
        });
        try { $response=$this->ban([$one->id,$two->id])->assertStatus(409); } finally { $active=false; }
        $this->assertStringNotContainsString('private-ban-storage-detail',$response->getContent()); $this->assertSame($before,$this->state());
    }

    public function test_capture_sql_failure_rethrows_original_only_when_adapter_is_disabled(): void
    {
        $user=$this->user(); $user->createToken('retain'); config(['legacy_bulk_ban_capture.enabled'=>false]); $active=true;
        DB::listen(function ($q) use (&$active): void { if ($active && preg_match('/^delete.*personal_access_tokens/i',$q->sql)) throw new \RuntimeException('fixture-native-delete'); });
        $before=$this->state(); try { $this->ban([$user->id])->assertStatus(500); } finally { $active=false; }
        $this->assertSame($before,$this->state());
    }

    public function test_bulk_ban_ticket_cannot_be_replayed_as_plan_propagation_and_verify_is_select_only(): void
    {
        $user=$this->user(); DB::transaction(function () use ($user): void {
            User::lockForUpdate()->findOrFail($user->id); $ticket=$this->capture()->beginBulkBan($user->id,$this->admin->id);
            try { $this->capture()->appendPlanPropagation($ticket); $this->fail('cross-policy ticket accepted'); }
            catch (LegacyCaptureShadowException $e) { $this->assertSame('CAPTURE_WRITER_TICKET_INVALID',$e->getMessage()); $this->assertNull($e->getPrevious()); }
        });
        $this->ban([$user->id])->assertOk(); DB::enableQueryLog(); DB::flushQueryLog(); $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
        foreach (DB::getQueryLog() as $q) $this->assertMatchesRegularExpression('/^select/i',$q['query']);
    }

    public function test_independent_mysql_current_staff_promotion_wait_rejects_whole_set(): void
    {
        $this->requireFork(); $user=$this->user(); $user->createToken('retain');
        [$parent,$child]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP); DB::purge(); $pid=pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child,10); fwrite($child,'R'); fread($child,1);
            try { fwrite($child,(string)$this->ban([$user->id])->getStatusCode()); } catch (\Throwable) { fwrite($child,'ERR'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0,$pid); fclose($child); stream_set_timeout($parent,10);
        try {
            $this->assertSame('R',fread($parent,1)); DB::beginTransaction(); User::lockForUpdate()->findOrFail($user->id)->updateQuietly(['is_staff'=>true]); fwrite($parent,'S');
            $read=[$parent]; $write=[]; $except=[]; $this->assertSame(0,stream_select($read,$write,$except,0,250000));
            DB::commit(); $this->assertSame('422',stream_get_contents($parent,32));
        } finally { if (DB::transactionLevel()) DB::rollBack(); fclose($parent); pcntl_waitpid($pid,$status); }
        $this->assertSame(0,pcntl_wexitstatus($status)); $this->assertFalse((bool)$user->fresh()->banned); $this->assertSame(1,$user->tokens()->count());
        $this->assertSame(0,DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_mysql_sigkill_before_after_commit_and_repeat_preserve_ban_token_event_atomicity(): void
    {
        $this->requireFork(); $one=$this->user(); $two=$this->user(); foreach ([$one,$two] as $user) { $this->backfill($user); $user->createToken('old'); }
        $ids=[$one->id,$two->id]; $before=$this->state(); $this->killBan($ids,false); $this->assertSame($before,$this->state());
        $this->killBan($ids,true); $this->assertSame(2,DB::table('tx_legacy_capture_shadow_event')->count());
        foreach ([$one,$two] as $user) { $this->assertTrue((bool)$user->fresh()->banned); $this->assertSame(0,$user->tokens()->count()); $this->assertTrue($this->capture()->verify($user->id)['page_ok']); }
        $history=$this->history(); $this->ban($ids)->assertOk()->assertJsonPath('data.updated',2); $this->assertSame($history,$this->history());
    }

    private function killBan(array $ids,bool $afterCommit): void
    {
        [$parent,$child]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP); DB::purge(); $pid=pcntl_fork();
        if ($pid === 0) {
            fclose($parent); $barrier=function () use ($child): void { fwrite($child,'K'); while (true) usleep(10000); };
            if ($afterCommit) Event::listen(TransactionCommitted::class,function ($e) use ($barrier): void { if ($e->connection->transactionLevel() === 0) $barrier(); });
            else DB::listen(function ($q) use ($barrier): void { if (preg_match('/^update.*tx_legacy_subscription_map/i',$q->sql)) $barrier(); });
            $this->ban($ids); exit(2);
        }
        $this->assertGreaterThan(0,$pid); fclose($child); stream_set_timeout($parent,10);
        try { $this->assertSame('K',fread($parent,1)); }
        finally { posix_kill($pid,SIGKILL); pcntl_waitpid($pid,$status); fclose($parent); $this->assertTrue(pcntl_wifsignaled($status)); $this->assertSame(SIGKILL,pcntl_wtermsig($status)); }
    }
    private function url(): string { return '/txapi/admin/bulk_ban_admin/users/ban'; }
    private function ban(array $ids): \Illuminate\Testing\TestResponse { return $this->postJson($this->url(),['scope'=>'selected','user_ids'=>$ids]); }
    private function filtered(array $filter): \Illuminate\Testing\TestResponse { return $this->postJson($this->url(),['scope'=>'filtered','filter'=>$filter]); }
    private function user(array $attributes=[]): User
    {
        $id=++$this->counter;
        return User::withoutEvents(fn ()=>User::create(array_replace(['email'=>'bulk-ban-'.$id.'@example.test','password'=>'bulk-ban-private-password',
            'uuid'=>sprintf('f0000000-0000-4000-8000-%012d',$id),'token'=>sprintf('%032d',$id),'plan_id'=>$this->plan->id,'group_id'=>1,
            'transfer_enable'=>1073741824,'u'=>10,'d'=>20,'balance'=>100,'commission_balance'=>20,'expired_at'=>time()+86400],$attributes)));
    }
    private function capture(): LegacyUserMutationCapture { return app(LegacyUserMutationCapture::class); }
    private function backfill(User $user): void { app(SubscriptionShadowBackfill::class)->run('ban-map-'.$user->id,100,app(LegacySubscriptionSnapshot::class)->context('UTC','USD')); }
    private function source(User $user): array
    {
        $row=(array)DB::table('tx_user')->where('id',$user->id)->select(LegacyUserMutationCapture::FIELDS)->first();
        foreach ($row as $key=>$value) $row[$key]=$key === 'banned' ? (bool)$value : ($value === null ? null : (string)$value);
        ksort($row,SORT_STRING); return $row;
    }
    private function decodedSource(string $raw): array
    { $row=json_decode($raw,true,flags:JSON_THROW_ON_ERROR); ksort($row,SORT_STRING); return $row; }
    private function history(): array
    { $result=[]; foreach (['tx_legacy_capture_shadow_stream','tx_legacy_capture_shadow_event','tx_legacy_subscription_map'] as $table) $result[$table]=DB::table($table)->orderBy('id')->get()->toJson(); return $result; }
    private function state(): array
    { $result=$this->history(); foreach (['tx_user','personal_access_tokens'] as $table) $result[$table]=DB::table($table)->orderBy('id')->get()->toJson(); return $result; }
    private function ownedState(): array
    { $result=[]; foreach (['tx_subscription','tx_subscription_terms','tx_usage_period','tx_access_subject','tx_subscription_slot'] as $table) $result[$table]=DB::table($table)->orderBy($table === 'tx_subscription_slot' ? 'user_id' : 'id')->get()->toJson(); return $result; }
    private function mysql(): bool { return getenv('CORE_CAPTURE_TEST_MYSQL') === '1'; }
    private function connection(): string { return $this->mysql() ? 'ban_capture_mysql_testing' : 'ban_capture_sqlite_testing'; }
    private function requireFork(): void { if (!$this->mysql() || !function_exists('pcntl_fork') || !function_exists('posix_kill')) $this->markTestSkipped('Requires dedicated MySQL database and independent process control.'); }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') throw new \RuntimeException('Bulk ban requires its dedicated test database.');
        Schema::disableForeignKeyConstraints(); try { foreach (Schema::getTableListing() as $table) Schema::drop($table); } finally { Schema::enableForeignKeyConstraints(); }
    }
}
