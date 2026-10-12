<?php

namespace Tests\Feature\Subscription;

use App\Jobs\TrafficBatchJob;
use App\Models\Server;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

class LegacyTrafficBatchCaptureTest extends TestCase
{
    private int $counter = 0;
    private TestHandler $log;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->connection();
        config(['database.default' => $connection, 'database.connections.'.$connection => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'),
                ['database' => ':memory:', 'foreign_key_constraints' => true])]);
        DB::purge($connection);
        if ($this->mysql()) { $this->clearMysql(); }
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        config(['core_shadow.isolated_copy' => true, 'legacy_capture_shadow.enabled' => true,
            'legacy_capture_shadow.run_key' => 'traffic-batch-fixture', 'legacy_capture_shadow.billing_timezone' => 'UTC',
            'legacy_capture_shadow.currency' => 'USD']);
        Bus::fake();
        Redis::shouldReceive('sadd')->zeroOrMoreTimes()->andReturn(1)->byDefault();
        $this->log = new TestHandler();
        Log::swap(new \Illuminate\Log\Logger(new Logger('capture-traffic-batch', [$this->log])));
    }

    protected function tearDown(): void
    {
        if ($this->mysql()) { $this->clearMysql(); }
        DB::purge($this->connection());
        parent::tearDown();
    }

    public function test_batch_captures_exact_billed_endpoints_and_preserves_raw_node_stats_and_projection(): void
    {
        $first = $this->user(); $second = $this->user(); $server = $this->server();
        $this->backfill($first); $projection = $this->projection();
        $before = [$this->source($first), $this->source($second)];
        $this->job($server, [$second->id => [3, 5], $first->id => [1, 3]], 'batch-a', 1.5)->handle();
        foreach ([$first, $second] as $index => $user) {
            $row = DB::table('tx_legacy_capture_shadow_event')->where('legacy_user_id', $user->id)->first();
            $this->assertSame($before[$index], json_decode($row->before_snapshot, true));
            $this->assertSame($this->source($user), json_decode($row->after_snapshot, true));
            $page = $this->capture()->verify($user->id);
            $this->assertTrue($page['page_ok']);
            $this->assertSame(['traffic.batch.settle', 'traffic-batch-capture/v1',
                'system_node_traffic_settlement', (string) $server->id, 'transaction-only'],
                [$page['events'][0]['source_kind'], $page['events'][0]['source_version'],
                    $page['events'][0]['actor_domain'], $page['events'][0]['actor_id'], $page['events'][0]['capture_boundary']]);
            foreach (['global_coverage_complete', 'projection_applied', 'migration_gate_open'] as $flag) {
                $this->assertFalse($page[$flag]);
            }
            foreach ([$user->email, $user->password, $user->uuid, $user->token, 'batch-a'] as $secret) {
                $this->assertStringNotContainsString($secret, json_encode([$row, $page]));
            }
        }
        $this->assertSame([11, 24, 14, 27], [(int) $first->fresh()->u, (int) $first->fresh()->d,
            (int) $second->fresh()->u, (int) $second->fresh()->d]);
        $this->assertSame([4, 8], [(int) $server->fresh()->u, (int) $server->fresh()->d]);
        $this->assertSame([5, 11], [(int) DB::table('tx_stat_user')->sum('u'), (int) DB::table('tx_stat_user')->sum('d')]);
        $this->assertSame([4, 8], [(int) DB::table('tx_stat_server')->sum('u'), (int) DB::table('tx_stat_server')->sum('d')]);
        $this->assertSame('source_changed', DB::table('tx_legacy_subscription_map')->value('state'));
        $this->assertSame($projection, $this->projection());
    }

    public function test_native_replays_and_conflicts_bypass_invalid_capture_context_and_missing_tables(): void
    {
        $user = $this->user(); $server = $this->server();
        $this->job($server, [$user->id => [1, 2]], 'same-batch')->handle();
        $committed = $this->nativeState();
        config(['legacy_capture_shadow.enabled' => 'invalid', 'legacy_capture_shadow.currency' => 'unsupported']);
        Schema::drop('tx_legacy_capture_shadow_event'); Schema::drop('tx_legacy_capture_shadow_stream');
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->job($server, [$user->id => [1, 2]], 'same-batch')->handle();
        $this->job($server, [$user->id => [9, 99]], 'same-batch')->handle();
        $this->assertCaptureSqlAbsent();
        $this->assertSame($committed, $this->nativeState());
    }

    public function test_disabled_production_preserves_bulk_prefetch_and_performs_zero_capture_sql(): void
    {
        $server = $this->server(); $payload = [];
        for ($i = 0; $i < 25; $i++) { $payload[$this->user()->id] = [1, 2]; }
        $this->app['env'] = 'production';
        config(['legacy_capture_shadow.enabled' => false,
            'legacy_capture_shadow.currency' => 'invalid', 'core_shadow.isolated_copy' => false]);
        Schema::drop('tx_legacy_capture_shadow_event'); Schema::drop('tx_legacy_capture_shadow_stream');
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->job($server, $payload)->handle();
        $this->assertCaptureSqlAbsent();
        $this->assertLessThanOrEqual(2, count(array_filter(DB::getQueryLog(), fn ($q) =>
            preg_match('/^select.*from ["`]tx_user["`]/i', $q['query']))));
        $this->assertSame(25, DB::table('tx_stat_user')->count());
        $this->assertSame([25, 50], [(int) $server->fresh()->u, (int) $server->fresh()->d]);
    }

    public function test_empty_invalid_rate_and_missing_users_keep_native_capture_free_paths(): void
    {
        $server = $this->server();
        config(['legacy_capture_shadow.enabled' => 'invalid']);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->job($server, [])->handle();
        $this->job($server, [1 => [1, 2]], 'bad-rate', -1)->handle();
        $this->job($server, [2147483647 => [1, 2]], 'missing-user')->handle();
        $this->assertCaptureSqlAbsent();
        $this->assertSame(1, DB::table('tx_traffic_batch')->count());
        $this->assertSame(0, DB::table('tx_stat_user')->count());
        $this->assertSame([0, 0], [(int) $server->fresh()->u, (int) $server->fresh()->d]);
    }

    public function test_floor_to_zero_preserves_raw_stats_without_inventing_user_event(): void
    {
        $user = $this->user(); $server = $this->server(); $this->backfill($user);
        $before = $this->source($user); $map = DB::table('tx_legacy_subscription_map')->first();
        $this->job($server, [$user->id => [1, 1]], 'zero-billed', 0.1)->handle();
        $this->assertSame($before, $this->source($user));
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertEquals($map, DB::table('tx_legacy_subscription_map')->first());
        $this->assertSame([1, 1], [(int) $server->fresh()->u, (int) $server->fresh()->d]);
        $this->assertSame(1, DB::table('tx_stat_user')->count());
    }

    public function test_uncovered_source_gap_rolls_back_claim_counters_stats_and_mapping(): void
    {
        $user = $this->user(); $server = $this->server(); $this->backfill($user);
        $this->job($server, [$user->id => [1, 2]], 'first')->handle();
        DB::table('tx_user')->where('id', $user->id)->increment('d');
        $before = $this->state();
        $this->assertFailure('CAPTURE_SOURCE_GAP', fn () => $this->job($server, [$user->id => [2, 3]], 'gap')->handle());
        $this->assertSame($before, $this->state());
        $this->assertContains('CAPTURE_SOURCE_GAP', $this->capture()->verify($user->id)['issues']);
    }

    public function test_strict_guard_or_negative_source_failure_cannot_commit_a_batch(): void
    {
        $user = $this->user(); $server = $this->server(); $before = $this->state();
        foreach ([['legacy_capture_shadow.enabled' => 'true'], ['core_shadow.isolated_copy' => false],
            ['legacy_capture_shadow.currency' => 'unsupported']] as $invalid) {
            $restore = []; foreach ($invalid as $key => $value) { $restore[$key] = config($key); }
            config($invalid);
            try { $this->job($server, [$user->id => [1, 2]])->handle(); $this->fail('Accepted unsafe capture configuration.'); }
            catch (LegacyCaptureShadowException $exception) { $this->assertNull($exception->getPrevious()); }
            finally { config($restore); }
            $this->assertSame($before, $this->state());
        }
        $this->app['env'] = 'production';
        $this->assertFailure('CAPTURE_ISOLATED_COPY_REQUIRED', fn () => $this->job($server, [$user->id => [1, 2]])->handle());
        $this->assertSame($before, $this->state()); $this->app['env'] = 'testing';
        DB::table('tx_user')->where('id', $user->id)->update(['balance' => -1]); $before = $this->state();
        $this->assertFailure('CAPTURE_SOURCE_INVALID', fn () => $this->job($server, [$user->id => [1, 2]])->handle());
        $this->assertSame($before, $this->state());
    }

    public function test_second_user_capture_failure_rolls_back_all_first_user_work_and_allows_original_retry(): void
    {
        $first = $this->user(); $second = $this->user(); $server = $this->server();
        $this->backfill($first); $before = $this->state(); $inserts = 0; $inject = true;
        DB::listen(function ($query) use (&$inserts, &$inject): void {
            if ($inject && preg_match('/^insert.*tx_legacy_capture_shadow_event/i', $query->sql) && ++$inserts === 2) {
                throw new \RuntimeException('traffic-fixture-private-storage-detail');
            }
        });
        $payload = [$second->id => [3, 4], $first->id => [1, 2]];
        $this->assertFailure('CAPTURE_STORAGE_OR_EVIDENCE_FAILED', fn () => $this->job($server, $payload)->handle());
        $this->assertSame($before, $this->state());
        $inject = false; $this->job($server, $payload)->handle();
        $this->assertSame(2, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertSame(1, DB::table('tx_traffic_batch')->count());
        $this->assertSame([4, 6], [(int) $server->fresh()->u, (int) $server->fresh()->d]);
    }

    public function test_native_server_overflow_after_capture_rolls_back_every_event(): void
    {
        $user = $this->user(); $server = $this->server(); $this->backfill($user);
        DB::table('tx_server')->where('id', $server->id)->update(['u' => PHP_INT_MAX - 1]); $before = $this->state();
        try { $this->job($server, [$user->id => [2, 3]])->handle(); $this->fail('Accepted native overflow.'); }
        catch (\RuntimeException $exception) { $this->assertSame('Server traffic counter overflow', $exception->getMessage()); }
        $this->assertSame($before, $this->state());
    }

    public function test_native_deadlock_retry_notifies_only_committed_users_and_captures_once(): void
    {
        $first = $this->user(); $second = $this->user(); $server = $this->server(); $retry = true;
        Redis::shouldReceive('sadd')->once()->with('traffic:pending_check', $first->id, $second->id)->andReturn(1);
        DB::listen(function ($query) use (&$retry): void {
            if ($retry && preg_match('/^update.*tx_server["`]/i', $query->sql)) {
                $retry = false;
                throw new \Illuminate\Database\DeadlockException('Deadlock found when trying to get lock; synthetic fixture');
            }
        });
        $this->job($server, [$second->id => [3, 4], $first->id => [1, 2]])->handle();
        $this->assertFalse($retry);
        $this->assertSame(1, DB::table('tx_traffic_batch')->count());
        $this->assertSame(2, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertSame([4, 6], [(int) $server->fresh()->u, (int) $server->fresh()->d]);
        $this->assertTrue($this->capture()->verify($first->id)['page_ok']);
        $this->assertTrue($this->capture()->verify($second->id)['page_ok']);
    }

    public function test_mixed_admin_and_batch_chain_preserves_old_hash_and_select_only_bounded_verification(): void
    {
        $user = $this->user(); $server = $this->server(); $this->backfill($user);
        DB::transaction(function () use ($user): void {
            User::lockForUpdate()->findOrFail($user->id); $before = $this->capture()->begin($user->id);
            DB::table('tx_user')->where('id', $user->id)->update(['speed_limit' => 2]);
            $this->capture()->append($before, $user->id);
        });
        $prior = DB::table('tx_legacy_capture_shadow_event')->first();
        $this->job($server, [$user->id => [1, 2]], 'one')->handle();
        $this->job($server, [$user->id => [3, 4]], 'two')->handle();
        $this->assertEquals($prior, DB::table('tx_legacy_capture_shadow_event')->first()); $before = $this->state();
        DB::enableQueryLog(); DB::flushQueryLog(); $kinds = [];
        foreach ([0, 1, 2] as $cursor) {
            $page = $this->capture()->verify($user->id, $cursor, 1); $this->assertTrue($page['page_ok']);
            $this->assertSame((string) ($cursor + 1), $page['next_cursor']); $kinds[] = $page['events'][0]['source_kind'];
        }
        foreach (DB::getQueryLog() as $query) { $this->assertMatchesRegularExpression('/^select/i', $query['query']); }
        $this->assertSame(['admin.user.update', 'traffic.batch.settle', 'traffic.batch.settle'], $kinds);
        $this->assertSame($before, $this->state());
    }

    public function test_transaction_and_cross_writer_tickets_are_enforced(): void
    {
        $user = $this->user(); $server = $this->server();
        $this->assertFailure('CAPTURE_TRANSACTION_REQUIRED', fn () => $this->capture()->beginTrafficBatchSettlement($user->id, $server->id));
        DB::transaction(function () use ($user, $server): void {
            User::lockForUpdate()->findOrFail($user->id);
            $ticket = $this->capture()->beginTrafficBatchSettlement($user->id, $server->id);
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->appendCronTrafficReset($ticket));
            $this->assertFailure('CAPTURE_WRITER_TICKET_INVALID', fn () => $this->capture()->append($ticket, $user->id));
        });
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_safe_exception_reporting_and_terminal_queue_log_preserve_native_retry_policy(): void
    {
        $user = $this->user(); $server = $this->server(); $job = $this->job($server, [$user->id => [1, 2]]);
        $previous = ini_get('zend.exception_ignore_args'); ini_set('zend.exception_ignore_args', '0');
        try {
            config(['legacy_capture_shadow.currency' => 'invalid']);
            try { $job->handle(); $this->fail('Capture unexpectedly succeeded.'); }
            catch (LegacyCaptureShadowException $exception) { report($exception); $job->failed($exception); }
        } finally { ini_set('zend.exception_ignore_args', $previous); }
        $records = $this->log->getRecords(); $this->assertCount(1, $records);
        $this->assertSame('LEGACY_TRAFFIC_BATCH_CAPTURE_RETRIES_EXHAUSTED', $records[0]['message']);
        $this->assertSame([], $records[0]['context']);
        $this->assertSame([3, 120, [5, 20, 60]], [$job->tries, $job->timeout, $job->backoff()]);
        $job->failed(new \RuntimeException('native failure')); $this->assertCount(1, $this->log->getRecords());
    }

    public function test_independent_mysql_same_batch_commits_only_one_event_and_one_charge(): void
    {
        $this->requireFork(); $user = $this->user(); $server = $this->server();
        $send = fn () => $this->job($server, [$user->id => [1, 2]], 'concurrent')->handle();
        $this->compete($send, $send);
        $this->assertSame(1, DB::table('tx_traffic_batch')->count());
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertSame([11, 22], [(int) $user->fresh()->u, (int) $user->fresh()->d]);
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    public function test_independent_mysql_different_servers_and_reversed_users_share_contiguous_streams(): void
    {
        $this->requireFork(); $first = $this->user(); $second = $this->user(); $left = $this->server(); $right = $this->server();
        $this->compete(fn () => $this->job($left, [$second->id => [1, 2], $first->id => [1, 2]], 'left')->handle(),
            fn () => $this->job($right, [$first->id => [3, 4], $second->id => [3, 4]], 'right')->handle());
        foreach ([$first, $second] as $user) {
            $this->assertSame([14, 26], [(int) $user->fresh()->u, (int) $user->fresh()->d]);
            $page = $this->capture()->verify($user->id); $this->assertTrue($page['page_ok']);
            $this->assertSame([(string) $left->id, (string) $right->id], array_column($page['events'], 'actor_id'));
        }
        $this->assertSame(4, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_mysql_stale_outer_snapshot_uses_current_user_endpoint_and_stream_tail(): void
    {
        $this->requireFork(); $user = $this->user(); $left = $this->server(); $right = $this->server();
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); DB::beginTransaction();
            DB::table('tx_user')->where('id', $user->id)->first(); DB::table('tx_legacy_capture_shadow_stream')->get();
            fwrite($child, 'R'); fread($child, 1);
            try { $this->job($right, [$user->id => [3, 4]], 'stale')->handle(); DB::commit(); fwrite($child, 'OK'); }
            catch (\Throwable $exception) {
                DB::rollBack();
                fwrite($child, $exception instanceof LegacyCaptureShadowException ? $exception->getMessage() : $exception::class);
            }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            $this->assertSame('R', fread($parent, 1));
            $this->job($left, [$user->id => [1, 2]], 'current')->handle(); fwrite($parent, 'S');
            $this->assertSame('OK', stream_get_contents($parent, 128));
        } finally { fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status));
        $row = DB::table('tx_legacy_capture_shadow_event')->where('sequence', 2)->first();
        $snapshot = json_decode($row->before_snapshot, true);
        $this->assertSame(['11', '22'], [$snapshot['u'], $snapshot['d']]);
        $this->assertSame(1, DB::table('tx_stat_user')->where('user_id', $user->id)->count());
        $this->assertSame([4, 6], [(int) DB::table('tx_stat_user')->sum('u'), (int) DB::table('tx_stat_user')->sum('d')]);
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    public function test_mysql_sigkill_before_and_after_commit_preserves_native_original_batch_replay(): void
    {
        $this->requireFork(); $user = $this->user(); $server = $this->server(); $this->backfill($user); $before = $this->state();
        $this->killBatch($user, $server, false); $this->assertSame($before, $this->state());
        $this->killBatch($user, $server, true);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertSame(1, DB::table('tx_traffic_batch')->count()); $committed = $this->state();
        config(['legacy_capture_shadow.enabled' => 'invalid']);
        $this->job($server, [$user->id => [1, 2]], 'kill-batch')->handle(); $this->assertSame($committed, $this->state());
        config(['legacy_capture_shadow.enabled' => true]); $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    private function compete(callable $winner, callable $waiting): void
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); fread($child, 1); fwrite($child, 'R');
            try { $waiting(); fwrite($child, 'OK'); } catch (\Throwable) { fwrite($child, 'FAIL'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            DB::beginTransaction(); $winner(); fwrite($parent, 'S'); $this->assertSame('R', fread($parent, 1));
            $read = [$parent]; $write = []; $except = [];
            $this->assertSame(0, stream_select($read, $write, $except, 0, 250000), 'Settlement escaped the native SQL fence.');
            DB::commit(); $this->assertSame('OK', stream_get_contents($parent, 32));
        } finally { if (DB::transactionLevel()) { DB::rollBack(); } fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    private function killBatch(User $user, Server $server, bool $afterCommit): void
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); $barrier = function () use ($child): void { fwrite($child, 'K'); while (true) { usleep(10000); } };
            if ($afterCommit) {
                Event::listen(TransactionCommitted::class, function ($event) use ($barrier): void {
                    if ($event->connection->transactionLevel() === 0) { $barrier(); }
                });
            } else {
                DB::listen(function ($query) use ($barrier): void {
                    if (preg_match('/^update.*tx_legacy_subscription_map/i', $query->sql)) { $barrier(); }
                });
            }
            $this->job($server, [$user->id => [1, 2]], 'kill-batch')->handle(); exit(2);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try { $this->assertSame('K', fread($parent, 1)); }
        finally {
            posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); fclose($parent);
            $this->assertTrue(pcntl_wifsignaled($status)); $this->assertSame(SIGKILL, pcntl_wtermsig($status));
        }
    }

    private function user(): User
    {
        $id = ++$this->counter;
        return User::create(['email' => 'batch-capture-'.$id.'@example.test', 'password' => 'batch-fixture-password',
            'uuid' => sprintf('50000000-0000-4000-8000-%012d', $id), 'token' => sprintf('%032d', $id),
            'transfer_enable' => 1000, 'u' => 10, 'd' => 20, 'balance' => 100, 'expired_at' => time() + 86400]);
    }
    private function server(): Server
    {
        return Server::create(['name' => 'capture-node', 'type' => Server::TYPE_VMESS, 'host' => '127.0.0.1',
            'port' => '443', 'server_port' => 443, 'rate' => 1, 'group_ids' => [1], 'enabled' => true]);
    }
    private function job(Server $server, array $data, string $id = 'fixture-batch', int|float $rate = 1): TrafficBatchJob
    {
        return new TrafficBatchJob(['id' => $server->id, 'rate' => $rate], $data, 'vmess', 1700000000, $id);
    }
    private function capture(): LegacyUserMutationCapture { return app(LegacyUserMutationCapture::class); }
    private function backfill(User $user): void
    {
        app(SubscriptionShadowBackfill::class)->run('traffic-map-'.$user->id, 100, app(LegacySubscriptionSnapshot::class)->context('UTC', 'USD'));
    }
    private function source(User $user): array
    {
        $row = (array) DB::table('tx_user')->where('id', $user->id)->select(LegacyUserMutationCapture::FIELDS)->first();
        foreach ($row as $key => $value) { $row[$key] = $key === 'banned' ? (bool) $value : ($value === null ? null : (string) $value); }
        ksort($row, SORT_STRING); return $row;
    }
    private function projection(): array
    {
        return $this->tables(['tx_subscription', 'tx_subscription_terms', 'tx_usage_period', 'tx_usage_adjustment',
            'tx_access_subject', 'tx_commerce_shadow_money_journal', 'tx_commerce_shadow_money_posting']);
    }
    private function nativeState(): array { return $this->tables(['tx_user', 'tx_server', 'tx_traffic_batch', 'tx_stat_user', 'tx_stat_server']); }
    private function state(): array
    {
        return $this->nativeState() + $this->projection() + $this->tables(['tx_legacy_capture_shadow_stream',
            'tx_legacy_capture_shadow_event', 'tx_legacy_subscription_map']);
    }
    private function tables(array $names): array
    {
        $out = []; foreach ($names as $name) { $out[$name] = DB::table($name)->orderBy('id')->get()->toJson(); } return $out;
    }
    private function assertFailure(string $code, callable $callback): void
    {
        try { $callback(); $this->fail('Accepted invalid traffic capture evidence.'); }
        catch (LegacyCaptureShadowException $exception) { $this->assertSame($code, $exception->getMessage()); $this->assertNull($exception->getPrevious()); }
    }
    private function assertCaptureSqlAbsent(): void
    {
        $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($q) =>
            preg_match('/tx_legacy_capture_shadow|tx_legacy_subscription_map/', $q['query']))));
    }
    private function mysql(): bool { return getenv('CORE_CAPTURE_TEST_MYSQL') === '1'; }
    private function connection(): string { return $this->mysql() ? 'traffic_batch_capture_mysql_testing' : 'traffic_batch_capture_sqlite_testing'; }
    private function requireFork(): void
    {
        if (!$this->mysql() || !function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Requires independent MariaDB sessions and process control.');
        }
    }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') { throw new \RuntimeException('Traffic capture needs its dedicated database.'); }
        Schema::disableForeignKeyConstraints();
        try { foreach (Schema::getTableListing() as $table) { Schema::drop($table); } }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
