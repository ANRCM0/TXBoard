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

class LegacyPlanPropagationCaptureTest extends TestCase
{
    private int $counter = 0;
    private Plan $plan;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $name = $this->connection();
        config(['database.default' => $name, 'database.connections.'.$name => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'), ['database' => ':memory:'])]);
        DB::purge($name); if ($this->mysql()) $this->clearMysql();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true])); Bus::fake();
        config(['core_shadow.isolated_copy' => true, 'legacy_capture_shadow.enabled' => true,
            'legacy_capture_shadow.run_key' => 'plan-propagation-fixture', 'legacy_capture_shadow.billing_timezone' => 'UTC',
            'legacy_capture_shadow.currency' => 'USD', 'legacy_plan_propagation_capture.enabled' => true,
            'legacy_observer_capture.enabled' => false]);
        admin_setting(['secure_path' => 'propagation_admin']);
        $this->plan = Plan::create(['name' => 'Propagation plan', 'group_id' => 1, 'transfer_enable' => 1,
            'speed_limit' => 5, 'device_limit' => 2, 'prices' => ['monthly' => 3], 'reset_traffic_method' => 0]);
        $this->admin = $this->user(['is_admin' => true, 'plan_id' => null]);
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    { if ($this->mysql()) $this->clearMysql(); DB::purge($this->connection()); parent::tearDown(); }

    public function test_actual_admin_force_records_exact_locked_users_units_and_unchanged_owned_state(): void
    {
        $one = $this->user(['u' => 9007199254740993]); $two = $this->user(); $outside = $this->user(['plan_id' => null]);
        foreach ([$one, $two] as $user) $this->backfill($user);
        $owned = $this->ownedState(); $outsideBefore = $this->source($outside);
        $before = [$this->source($one), $this->source($two)];
        $saves = 0; Event::listen('eloquent.saving: '.User::class, function () use (&$saves): void { ++$saves; });
        $this->edit(['group_id' => 2, 'speed_limit' => null, 'device_limit' => 4])->assertOk()->assertJsonPath('data.id', $this->plan->id);
        $this->assertSame(0, $saves); $this->assertSame($owned, $this->ownedState()); $this->assertSame($outsideBefore, $this->source($outside));
        $rows = DB::table('tx_legacy_capture_shadow_event')->orderBy('legacy_user_id')->get(); $this->assertCount(2, $rows);
        foreach ([$one, $two] as $index => $user) {
            $after = $this->source($user);
            $this->assertSame('3221225472', $after['transfer_enable']); $this->assertSame('2', $after['group_id']);
            $this->assertNull($after['speed_limit']); $this->assertSame('4', $after['device_limit']);
            $this->assertSame($before[$index], json_decode($rows[$index]->before_snapshot, true));
            $this->assertSame($after, json_decode($rows[$index]->after_snapshot, true));
            foreach (['u','d','expired_at','last_reset_at','next_reset_at','reset_count','banned','balance','commission_balance','plan_id'] as $key)
                $this->assertSame($before[$index][$key], $after[$key]);
            $this->assertSame('source_changed', DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $user->id)->value('state'));
            $page = $this->capture()->verify($user->id); $this->assertTrue($page['page_ok']);
            $this->assertFalse($page['global_coverage_complete']); $this->assertFalse($page['migration_gate_open']);
            $this->assertSame(['admin.plan.propagate','plan-propagation-capture/v1','admin_user',(string)$this->admin->id],
                array_map(fn ($key) => $page['events'][0][$key], ['source_kind','source_version','actor_domain','actor_id']));
            foreach ([$user->email,$user->password,$user->uuid,$user->token] as $secret)
                $this->assertStringNotContainsString($secret, json_encode([$rows[$index],$page]));
        }
        $this->assertSame(3, (int)$this->plan->fresh()->transfer_enable);
    }

    public function test_default_off_keeps_one_native_bulk_update_in_production_with_no_capture_sql(): void
    {
        $user = $this->user();
        config(['legacy_plan_propagation_capture.enabled' => false, 'legacy_capture_shadow.enabled' => 'invalid', 'core_shadow.isolated_copy' => false]);
        $this->app['env'] = 'production';
        // Remove empty dependent evidence tables first on engines enforcing FKs.
        foreach (['tx_legacy_money_shadow_posting','tx_legacy_money_shadow_step','tx_legacy_money_shadow_checkpoint',
            'tx_legacy_capture_shadow_event','tx_legacy_capture_shadow_stream'] as $table) Schema::drop($table);
        DB::enableQueryLog(); DB::flushQueryLog(); $this->edit()->assertOk();
        $queries = DB::getQueryLog();
        $this->assertSame([], array_values(array_filter($queries, fn ($q) => preg_match('/tx_legacy_capture_shadow|tx_legacy_subscription_map/', $q['query']))));
        $this->assertCount(1, array_filter($queries, fn ($q) => preg_match('/^update.*tx_user/i', $q['query'])));
        $this->assertSame(3221225472, (int)$user->fresh()->transfer_enable);
    }

    public function test_no_force_never_enters_capture_and_identical_force_preserves_noop_history(): void
    {
        $user = $this->user(); config(['legacy_plan_propagation_capture.enabled' => 'invalid']);
        $this->edit(['force_update' => false])->assertOk(); $this->assertSame('1073741824', $this->source($user)['transfer_enable']);
        config(['legacy_plan_propagation_capture.enabled' => true]); $this->edit()->assertOk();
        $state = $this->state(true); $this->edit()->assertOk();
        $this->assertSame($state, $this->state(true)); $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_original_admin_path_role_validation_and_new_plan_force_rejections(): void
    {
        $user = $this->user(); Sanctum::actingAs($user); $this->edit()->assertStatus(403);
        Sanctum::actingAs($this->admin); $this->postJson('/txapi/admin/wrong/plans', $this->input())->assertStatus(404);
        $this->edit(['transfer_enable' => 0])->assertStatus(422);
        $input = $this->input(); unset($input['id']); $this->postJson($this->url(), $input)->assertStatus(422);
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_strict_context_rejects_even_empty_subscriber_set_before_plan_mutation(): void
    {
        $original = $this->plan->fresh()->getAttributes();
        foreach (['1', 1, null] as $invalid) {
            config(['legacy_plan_propagation_capture.enabled' => $invalid]); $this->edit()->assertStatus(409);
        }
        config(['legacy_plan_propagation_capture.enabled' => true, 'legacy_capture_shadow.enabled' => false]); $this->edit()->assertStatus(409);
        config(['legacy_capture_shadow.enabled' => true, 'core_shadow.isolated_copy' => false]); $this->edit()->assertStatus(409);
        config(['core_shadow.isolated_copy' => true]); $this->app['env'] = 'production'; $this->edit()->assertStatus(409);
        $this->app['env'] = 'testing'; config(['legacy_capture_shadow.currency' => 'JPY']); $this->edit()->assertStatus(409);
        $this->assertSame($original, $this->plan->fresh()->getAttributes());
    }

    public function test_more_than_thousand_refuses_before_mutation_without_partial_scope(): void
    {
        $records = [];
        for ($i = 1; $i <= 1001; ++$i) $records[] = ['email'=>'bulk-'.$i.'@example.test','password'=>'bulk-private',
            'uuid'=>sprintf('d0000000-0000-4000-8000-%012d',$i),'token'=>sprintf('%032d',$i),
            'plan_id'=>$this->plan->id,'transfer_enable'=>1073741824,'created_at'=>time(),'updated_at'=>time()];
        foreach (array_chunk($records,100) as $batch) DB::table('tx_user')->insert($batch);
        $before = $this->state(); $this->edit()->assertStatus(409); $this->assertSame($before, $this->state());
    }

    public function test_user_created_after_locked_selection_is_explicitly_outside_the_frozen_set(): void
    {
        $selected = $this->user(); $late = null; $active = true;
        DB::listen(function ($q) use (&$active, &$late): void {
            if ($active && preg_match('/^select.*tx_user.*plan_id.*limit 1001/i', $q->sql)) {
                $active = false;
                $late = $this->user();
            }
        });
        $this->edit()->assertOk(); $this->assertInstanceOf(User::class, $late);
        $this->assertSame('3221225472', $this->source($selected)['transfer_enable']);
        $this->assertSame('1073741824', $this->source($late)['transfer_enable']);
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->where('legacy_user_id', $late->id)->count());
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_later_source_gap_rolls_back_earlier_append_all_users_and_plan(): void
    {
        $one = $this->user(); $two = $this->user(); $this->edit()->assertOk();
        DB::table('tx_user')->where('id',$two->id)->update(['u'=>99]); $before = $this->state();
        $this->edit(['transfer_enable'=>4])->assertStatus(409); $this->assertSame($before,$this->state());
        $this->assertTrue($this->capture()->verify($one->id)['page_ok']);
        $this->assertContains('CAPTURE_SOURCE_GAP',$this->capture()->verify($two->id)['issues']);
    }

    public function test_storage_failure_after_first_append_rolls_back_the_whole_native_transaction(): void
    {
        $one = $this->user(); $two = $this->user(); foreach ([$one,$two] as $user) $this->backfill($user);
        $before = $this->state(); $active = true; $inserts = 0;
        DB::listen(function ($q) use (&$active,&$inserts): void {
            if ($active && preg_match('/^insert.*tx_legacy_capture_shadow_event/i',$q->sql) && ++$inserts === 2)
                throw new \RuntimeException('private-propagation-input@example.test');
        });
        try { $response = $this->edit()->assertStatus(409); } finally { $active = false; }
        $this->assertStringNotContainsString('private-propagation-input', $response->getContent()); $this->assertSame($before,$this->state());
    }

    public function test_original_plan_save_false_commits_actual_user_endpoint_and_thrown_save_rolls_back(): void
    {
        $user = $this->user(); $mode = 'false';
        Event::listen('eloquent.saving: '.Plan::class, function () use (&$mode) {
            if ($mode === 'false') return false;
            if ($mode === 'throw') throw new \RuntimeException('fixture-plan-save-failure');
        });
        $this->edit()->assertOk(); $this->assertSame(1,(int)$this->plan->fresh()->transfer_enable);
        $this->assertSame('3221225472',$this->source($user)['transfer_enable']); $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
        $before = $this->state(); $mode = 'throw'; $this->edit(['transfer_enable'=>4])->assertStatus(500);
        $mode = 'off'; $this->assertSame($before,$this->state());
    }

    public function test_plan_ticket_cannot_be_used_by_another_fixed_writer_and_context_needs_transaction(): void
    {
        $user = $this->user();
        DB::transaction(function () use ($user): void {
            Plan::lockForUpdate()->findOrFail($this->plan->id); User::lockForUpdate()->findOrFail($user->id);
            $ticket = $this->capture()->beginPlanPropagation($user->id,$this->admin->id);
            try { $this->capture()->appendScheduleRepair($ticket); $this->fail('cross-policy ticket accepted'); }
            catch (LegacyCaptureShadowException $e) { $this->assertSame('CAPTURE_WRITER_TICKET_INVALID',$e->getMessage()); $this->assertNull($e->getPrevious()); }
        });
        $this->expectExceptionMessage('CAPTURE_TRANSACTION_REQUIRED'); $this->capture()->assertPlanPropagationContext();
    }

    public function test_readonly_verifier_includes_plan_policy_without_writes(): void
    {
        $user = $this->user(); $this->edit()->assertOk(); DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
        foreach (DB::getQueryLog() as $q) $this->assertMatchesRegularExpression('/^select/i',$q['query']);
    }

    public function test_independent_mysql_plan_wait_rechecks_current_membership(): void
    {
        $this->requireFork(); $user = $this->user(); $before = $this->source($user);
        [$parent,$child] = stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child,10); fwrite($child,'R'); fread($child,1);
            try { fwrite($child,(string)$this->edit()->getStatusCode()); } catch (\Throwable) { fwrite($child,'ERR'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0,$pid); fclose($child); stream_set_timeout($parent,10);
        try {
            $this->assertSame('R',fread($parent,1)); DB::beginTransaction(); Plan::lockForUpdate()->findOrFail($this->plan->id);
            DB::table('tx_user')->where('id',$user->id)->update(['plan_id'=>null]); fwrite($parent,'S');
            $read=[$parent]; $write=[]; $except=[]; $this->assertSame(0,stream_select($read,$write,$except,0,250000));
            DB::commit(); $this->assertSame('200',stream_get_contents($parent,32));
        } finally { if (DB::transactionLevel()) DB::rollBack(); fclose($parent); pcntl_waitpid($pid,$status); }
        $this->assertSame(0,pcntl_wexitstatus($status)); $before['plan_id']=null; $this->assertSame($before,$this->source($user));
        $this->assertSame(0,DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_mysql_sigkill_before_and_after_commit_keeps_atomic_set_and_noop_replay(): void
    {
        $this->requireFork(); $one=$this->user(); $two=$this->user(); foreach ([$one,$two] as $user) $this->backfill($user);
        $before=$this->state(); $this->killEdit(false); $this->assertSame($before,$this->state());
        $this->killEdit(true); $this->assertSame(2,DB::table('tx_legacy_capture_shadow_event')->count());
        $state=$this->state(true); $this->edit()->assertOk(); $this->assertSame($state,$this->state(true));
        foreach ([$one,$two] as $user) $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    private function killEdit(bool $afterCommit): void
    {
        [$parent,$child]=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP); DB::purge(); $pid=pcntl_fork();
        if ($pid === 0) {
            fclose($parent); $barrier=function () use ($child): void { fwrite($child,'K'); while (true) usleep(10000); };
            if ($afterCommit) Event::listen(TransactionCommitted::class,function ($event) use ($barrier): void { if ($event->connection->transactionLevel() === 0) $barrier(); });
            else DB::listen(function ($q) use ($barrier): void { if (preg_match('/^update.*tx_legacy_subscription_map/i',$q->sql)) $barrier(); });
            $this->edit(); exit(2);
        }
        $this->assertGreaterThan(0,$pid); fclose($child); stream_set_timeout($parent,10);
        try { $this->assertSame('K',fread($parent,1)); }
        finally { posix_kill($pid,SIGKILL); pcntl_waitpid($pid,$status); fclose($parent); $this->assertTrue(pcntl_wifsignaled($status)); $this->assertSame(SIGKILL,pcntl_wtermsig($status)); }
    }
    private function url(): string { return '/txapi/admin/propagation_admin/plans'; }
    private function input(): array { return ['id'=>$this->plan->id,'name'=>'Propagation plan','transfer_enable'=>3,'force_update'=>true]; }
    private function edit(array $changes=[]): \Illuminate\Testing\TestResponse { return $this->postJson($this->url(),array_replace($this->input(),$changes)); }
    private function user(array $attributes=[]): User
    {
        $id=++$this->counter;
        return User::withoutEvents(fn () => User::create(array_replace(['email'=>'propagation-'.$id.'@example.test','password'=>'propagation-private-password',
            'uuid'=>sprintf('e0000000-0000-4000-8000-%012d',$id),'token'=>sprintf('%032d',$id), 'plan_id'=>$this->plan->id,
            'group_id'=>1,'transfer_enable'=>1073741824,'u'=>10,'d'=>20,'balance'=>100,'commission_balance'=>20,
            'speed_limit'=>5,'device_limit'=>2,'expired_at'=>time()+86400,'next_reset_at'=>time()+3600],$attributes)));
    }
    private function capture(): LegacyUserMutationCapture { return app(LegacyUserMutationCapture::class); }
    private function backfill(User $user): void { app(SubscriptionShadowBackfill::class)->run('plan-map-'.$user->id,100,app(LegacySubscriptionSnapshot::class)->context('UTC','USD')); }
    private function source(User $user): array
    {
        $row=(array)DB::table('tx_user')->where('id',$user->id)->select(LegacyUserMutationCapture::FIELDS)->first();
        foreach ($row as $key=>$value) $row[$key]=$key === 'banned' ? (bool)$value : ($value === null ? null : (string)$value);
        ksort($row,SORT_STRING); return $row;
    }
    private function state(bool $ignoreTimestamps=false): array
    {
        $result=[];
        foreach (['tx_plan','tx_user','tx_legacy_capture_shadow_stream','tx_legacy_capture_shadow_event','tx_legacy_subscription_map'] as $table) {
            $rows=DB::table($table)->orderBy('id')->get();
            if ($ignoreTimestamps && in_array($table,['tx_plan','tx_user'],true)) foreach ($rows as $row) unset($row->updated_at);
            $result[$table]=$rows->toJson();
        }
        return $result;
    }
    private function ownedState(): array
    {
        $result=[];
        foreach (['tx_subscription','tx_subscription_terms','tx_usage_period','tx_access_subject','tx_subscription_slot'] as $table)
            $result[$table]=DB::table($table)->orderBy($table === 'tx_subscription_slot' ? 'user_id' : 'id')->get()->toJson();
        return $result;
    }
    private function mysql(): bool { return getenv('CORE_CAPTURE_TEST_MYSQL') === '1'; }
    private function connection(): string { return $this->mysql() ? 'plan_capture_mysql_testing' : 'plan_capture_sqlite_testing'; }
    private function requireFork(): void { if (!$this->mysql() || !function_exists('pcntl_fork') || !function_exists('posix_kill')) $this->markTestSkipped('Requires dedicated MySQL database and independent process control.'); }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') throw new \RuntimeException('Plan capture requires its dedicated database.');
        Schema::disableForeignKeyConstraints(); try { foreach (Schema::getTableListing() as $table) Schema::drop($table); } finally { Schema::enableForeignKeyConstraints(); }
    }
}
