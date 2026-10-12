<?php

namespace Tests\Feature\Subscription;

use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacySubscriptionSnapshot;
use App\Services\Subscription\LegacyUserMutationCapture;
use App\Services\Subscription\SubscriptionShadowBackfill;
use App\Services\TrafficResetService;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

class LegacyScheduleRepairCaptureTest extends TestCase
{
    private int $counter = 0;
    private TestHandler $log;
    protected function setUp(): void
    {
        parent::setUp(); $name = $this->connection();
        config(['database.default' => $name, 'database.connections.'.$name => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'), ['database' => ':memory:'])]);
        DB::purge($name); if ($this->mysql()) $this->clearMysql();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true])); Bus::fake();
        config(['core_shadow.isolated_copy' => true, 'legacy_capture_shadow.enabled' => true,
            'legacy_capture_shadow.run_key' => 'schedule-repair-fixture', 'legacy_capture_shadow.billing_timezone' => 'UTC',
            'legacy_capture_shadow.currency' => 'USD', 'legacy_observer_capture.enabled' => false]);
        $this->log = new TestHandler(); Log::swap(new \Illuminate\Log\Logger(new Logger('schedule-repair', [$this->log])));
    }
    protected function tearDown(): void
    { if ($this->mysql()) $this->clearMysql(); DB::purge($this->connection()); parent::tearDown(); }

    public function test_actual_fix_null_cli_exact_schedule_mapping_and_unchanged_counters(): void
    {
        $user = $this->user(); $user->updateQuietly(['next_reset_at' => null]); $this->backfill($user);
        $before = $this->source($user); $next = $this->service()->calculateNextResetTime($user)->timestamp;
        $this->assertSame(0, Artisan::call('reset:traffic', ['--fix-null' => true, '--capture-schedule' => true]));
        $event = DB::table('tx_legacy_capture_shadow_event')->first();
        $this->assertSame($before, json_decode($event->before_snapshot, true));
        $after = $this->source($user); $this->assertSame((string) $next, $after['next_reset_at']);
        $this->assertSame($after, json_decode($event->after_snapshot, true)); $after['next_reset_at'] = null; $this->assertSame($before, $after);
        $this->assertSame(0, TrafficResetLog::count());
        $this->assertSame('source_changed', DB::table('tx_legacy_subscription_map')->value('state'));
        DB::enableQueryLog(); DB::flushQueryLog(); $page = $this->capture()->verify($user->id);
        foreach (DB::getQueryLog() as $q) $this->assertMatchesRegularExpression('/^select/i', $q['query']);
        $this->assertTrue($page['page_ok']); $summary = $page['events'][0];
        $this->assertSame(['user.schedule.repair', 'schedule-repair-capture/v1', 'system_user_schedule_repair', (string) $user->id],
            [$summary['source_kind'], $summary['source_version'], $summary['actor_domain'], $summary['actor_id']]);
        foreach ([$user->email, $user->password, $user->uuid, $user->token] as $secret)
            $this->assertStringNotContainsString($secret, json_encode([$event, $page, $this->log->getRecords()]));
    }

    public function test_actual_force_cli_replay_noop_and_both_native_options_fix_null_priority(): void
    {
        $user = $this->user(); $user->updateQuietly(['next_reset_at' => 1]);
        $this->assertSame(0, Artisan::call('reset:traffic', ['--force' => true, '--capture-schedule' => true]));
        $first = DB::table('tx_legacy_capture_shadow_event')->first();
        $this->assertSame(0, Artisan::call('reset:traffic', ['--force' => true, '--capture-schedule' => true]));
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count()); $this->assertEquals($first, DB::table('tx_legacy_capture_shadow_event')->first());
        $this->assertSame(0, Artisan::call('reset:traffic', ['--fix-null' => true, '--force' => true, '--capture-schedule' => true]));
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    public function test_no_marker_production_keeps_native_repair_and_zero_capture_queries(): void
    {
        $user = $this->user(); $user->updateQuietly(['next_reset_at' => null]);
        config(['legacy_capture_shadow.enabled' => 'invalid', 'core_shadow.isolated_copy' => false]); $this->app['env'] = 'production';
        Schema::drop('tx_legacy_capture_shadow_event'); Schema::drop('tx_legacy_capture_shadow_stream');
        DB::enableQueryLog(); DB::flushQueryLog(); $this->assertSame(0, Artisan::call('reset:traffic', ['--fix-null' => true]));
        $this->assertNoCaptureQueries(); $this->assertNotNull($user->fresh()->next_reset_at);
    }

    public function test_marker_requires_repair_mode_before_mutation_and_current_ineligible_users_skip(): void
    {
        $user = $this->user(); $before = $this->source($user);
        $this->assertSame(1, Artisan::call('reset:traffic', ['--capture-schedule' => true]));
        $this->assertStringContainsString('CAPTURE_SCHEDULE_REPAIR_MODE_REQUIRED', Artisan::output()); $this->assertSame($before, $this->source($user));
        config(['legacy_capture_shadow.enabled' => 'invalid']); DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertFalse($this->service()->repairScheduleCaptured($user->id, true));
        $user->updateQuietly(['banned' => true]); $this->assertFalse($this->service()->repairScheduleCaptured($user->id, false));
        $this->assertFalse($this->service()->repairScheduleCaptured(2147483647, false)); $this->assertNoCaptureQueries();
    }

    public function test_later_user_capture_failure_retains_earlier_commit_safe_errors_and_original_success_exit(): void
    {
        $first = $this->user(); $second = $this->user();
        foreach ([$first, $second] as $user) $user->updateQuietly(['next_reset_at' => null]);
        $active = true; $insert = 0; DB::listen(function ($query) use (&$active, &$insert): void {
            if ($active && preg_match('/^insert.*tx_legacy_capture_shadow_event/i', $query->sql) && ++$insert === 2)
                throw new \RuntimeException('private-schedule-storage-detail');
        });
        $this->assertSame(0, Artisan::call('reset:traffic', ['--fix-null' => true, '--capture-schedule' => true])); $active = false;
        $this->assertNotNull($first->fresh()->next_reset_at); $this->assertNull($second->fresh()->next_reset_at);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count()); $this->assertCount(1, $this->log->getRecords());
        $this->assertSame('LEGACY_SCHEDULE_REPAIR_CAPTURE_FAILED', $this->log->getRecords()[0]['message']);
        $this->assertSame([], $this->log->getRecords()[0]['context']); $this->assertStringNotContainsString('private-schedule-storage-detail', Artisan::output());
        $this->assertTrue($this->capture()->verify($first->id)['page_ok']);
    }

    public function test_strict_isolated_context_and_outer_transaction_refuse_without_repair(): void
    {
        $user = $this->user(); $user->updateQuietly(['next_reset_at' => null]);
        config(['legacy_capture_shadow.enabled' => false]); $this->failure('CAPTURE_SCHEDULE_ISOLATED_REQUIRED', fn () => $this->service()->repairScheduleCaptured($user->id, true));
        config(['legacy_capture_shadow.enabled' => true, 'core_shadow.isolated_copy' => false]);
        $this->failure('CAPTURE_ISOLATED_COPY_REQUIRED', fn () => $this->service()->repairScheduleCaptured($user->id, true));
        config(['core_shadow.isolated_copy' => true]); DB::transaction(function () use ($user): void {
            $this->failure('CAPTURE_SCHEDULE_OUTER_TRANSACTION_UNSUPPORTED', fn () => $this->service()->repairScheduleCaptured($user->id, true));
        });
        $this->assertNull($user->fresh()->next_reset_at); $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_source_gap_is_not_rebased_and_original_model_save_false_has_no_false_event(): void
    {
        $user = $this->user(); $user->updateQuietly(['next_reset_at' => null]);
        $name = 'eloquent.saving: '.User::class; Event::listen($name, static fn () => false);
        try { $this->assertTrue($this->service()->repairScheduleCaptured($user->id, true)); } finally { Event::forget($name); }
        $this->assertNull($user->fresh()->next_reset_at); $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertTrue($this->service()->repairScheduleCaptured($user->id, true));
        DB::table('tx_user')->where('id', $user->id)->update(['next_reset_at' => 1]); $before = $this->source($user);
        $this->failure('CAPTURE_SOURCE_GAP', fn () => $this->service()->repairScheduleCaptured($user->id, false));
        $this->assertSame($before, $this->source($user)); $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_independent_mysql_current_fix_null_candidate_is_skipped_after_competing_commit(): void
    {
        $this->requireFork(); $user = $this->user(); $user->updateQuietly(['next_reset_at' => null]);
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); fwrite($child, 'R'); fread($child, 1);
            try { fwrite($child, $this->service()->repairScheduleCaptured($user->id, true) ? 'BAD' : 'NO'); } catch (\Throwable) { fwrite($child, 'ERR'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            $this->assertSame('R', fread($parent, 1)); DB::beginTransaction();
            User::lockForUpdate()->findOrFail($user->id)->updateQuietly(['next_reset_at' => 12345]); fwrite($parent, 'S');
            $read = [$parent]; $write = []; $except = []; $this->assertSame(0, stream_select($read, $write, $except, 0, 250000));
            DB::commit(); $this->assertSame('NO', stream_get_contents($parent, 32));
        } finally { if (DB::transactionLevel()) DB::rollBack(); fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status)); $this->assertSame(12345, (int) $user->fresh()->next_reset_at);
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_mysql_sigkill_before_and_after_commit_preserves_repair_and_noop_recovery(): void
    {
        $this->requireFork(); $user = $this->user(); $user->updateQuietly(['next_reset_at' => null]); $this->backfill($user);
        $this->killRepair($user, false); $this->assertNull($user->fresh()->next_reset_at);
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->killRepair($user, true); $this->assertNotNull($user->fresh()->next_reset_at);
        $this->assertFalse($this->service()->repairScheduleCaptured($user->id, true));
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']); $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    private function killRepair(User $user, bool $afterCommit): void
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); $barrier = function () use ($child): void { fwrite($child, 'K'); while (true) usleep(10000); };
            if ($afterCommit) Event::listen(TransactionCommitted::class, function ($event) use ($barrier): void {
                if ($event->connection->transactionLevel() === 0) $barrier();
            });
            else DB::listen(function ($query) use ($barrier): void {
                if (preg_match('/^update.*tx_legacy_subscription_map/i', $query->sql)) $barrier();
            });
            $this->service()->repairScheduleCaptured($user->id, true); exit(2);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try { $this->assertSame('K', fread($parent, 1)); }
        finally { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); fclose($parent);
            $this->assertTrue(pcntl_wifsignaled($status)); $this->assertSame(SIGKILL, pcntl_wtermsig($status)); }
    }
    private function user(): User
    {
        $plan = Plan::create(['name' => 'repair-plan', 'group_id' => 1, 'transfer_enable' => 10,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY, 'prices' => ['monthly' => 10], 'show' => 1, 'sell' => 1]);
        $id = ++$this->counter; return User::create(['email' => 'repair-'.$id.'@example.test', 'password' => 'repair-private-password',
            'uuid' => sprintf('a0000000-0000-4000-8000-%012d', $id), 'token' => sprintf('%032d', $id),
            'plan_id' => $plan->id, 'transfer_enable' => 1000, 'u' => 10, 'd' => 20, 'balance' => 100, 'expired_at' => time()+86400]);
    }
    private function service(): TrafficResetService { return app(TrafficResetService::class); }
    private function capture(): LegacyUserMutationCapture { return app(LegacyUserMutationCapture::class); }
    private function backfill(User $user): void
    { app(SubscriptionShadowBackfill::class)->run('repair-map-'.$user->id, 100, app(LegacySubscriptionSnapshot::class)->context('UTC', 'USD')); }
    private function source(User $user): array
    {
        $row = (array) DB::table('tx_user')->where('id', $user->id)->select(LegacyUserMutationCapture::FIELDS)->first();
        foreach ($row as $key => $value) $row[$key] = $key === 'banned' ? (bool) $value : ($value === null ? null : (string) $value);
        ksort($row, SORT_STRING); return $row;
    }
    private function assertNoCaptureQueries(): void
    { $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/tx_legacy_capture_shadow|tx_legacy_subscription_map/', $q['query'])))); }
    private function failure(string $code, callable $callback): void
    {
        try { $callback(); $this->fail('Invalid schedule capture accepted.'); }
        catch (LegacyCaptureShadowException $e) { $this->assertSame($code, $e->getMessage()); $this->assertNull($e->getPrevious()); }
    }
    private function mysql(): bool { return getenv('CORE_CAPTURE_TEST_MYSQL') === '1'; }
    private function connection(): string { return $this->mysql() ? 'repair_capture_mysql_testing' : 'repair_capture_sqlite_testing'; }
    private function requireFork(): void
    { if (!$this->mysql() || !function_exists('pcntl_fork') || !function_exists('posix_kill')) $this->markTestSkipped('Requires local independent MariaDB sessions and process control.'); }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') throw new \RuntimeException('Schedule repair needs its dedicated database.');
        Schema::disableForeignKeyConstraints(); try { foreach (Schema::getTableListing() as $table) Schema::drop($table); }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
