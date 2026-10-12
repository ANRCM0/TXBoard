<?php

namespace Tests\Feature\Subscription;

use App\Jobs\TrafficBatchJob;
use App\Jobs\TrafficFetchJob;
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

class LegacyTrafficFetchCaptureTest extends TestCase
{
    private int $counter = 0;
    private TestHandler $log;

    protected function setUp(): void
    {
        parent::setUp(); $connection = $this->connection();
        config(['database.default' => $connection, 'database.connections.'.$connection => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'),
                ['database' => ':memory:', 'foreign_key_constraints' => true])]);
        DB::purge($connection); if ($this->mysql()) { $this->clearMysql(); }
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        config(['core_shadow.isolated_copy' => true, 'legacy_capture_shadow.enabled' => true,
            'legacy_capture_shadow.run_key' => 'traffic-fetch-fixture', 'legacy_capture_shadow.billing_timezone' => 'UTC',
            'legacy_capture_shadow.currency' => 'USD']);
        Bus::fake(); Redis::shouldReceive('sadd')->zeroOrMoreTimes()->andReturn(1)->byDefault();
        $this->log = new TestHandler(); Log::swap(new \Illuminate\Log\Logger(new Logger('capture-traffic-fetch', [$this->log])));
    }

    protected function tearDown(): void
    {
        if ($this->mysql()) { $this->clearMysql(); }
        DB::purge($this->connection()); parent::tearDown();
    }

    public function test_exact_native_endpoints_mapping_and_two_identical_calls_remain_two_increments(): void
    {
        $user = $this->user(); $server = $this->server(); $this->backfill($user);
        $projection = $this->projection(); $before = $this->source($user);
        $this->job($server, [$user->id => [2, 4]], 1.5)->handle();
        $first = DB::table('tx_legacy_capture_shadow_event')->first();
        $this->assertSame($before, json_decode($first->before_snapshot, true));
        $this->assertSame($this->source($user), json_decode($first->after_snapshot, true));
        $this->job($server, [$user->id => [2, 4]], 1.5)->handle();
        $this->assertSame([16, 32], [(int) $user->fresh()->u, (int) $user->fresh()->d]);
        $this->assertEquals($first, DB::table('tx_legacy_capture_shadow_event')->first());
        $page = $this->capture()->verify($user->id); $this->assertTrue($page['page_ok']);
        $this->assertSame(['traffic.fetch.apply', 'traffic.fetch.apply'], array_column($page['events'], 'source_kind'));
        $this->assertSame(['system_legacy_traffic_fetch', 'system_legacy_traffic_fetch'], array_column($page['events'], 'actor_domain'));
        $this->assertSame([(string) $server->id, (string) $server->id], array_column($page['events'], 'actor_id'));
        $this->assertSame('source_changed', DB::table('tx_legacy_subscription_map')->value('state'));
        $this->assertSame($projection, $this->projection());
        foreach (['tx_traffic_batch', 'tx_stat_user', 'tx_stat_server'] as $table) $this->assertSame(0, DB::table($table)->count());
        foreach ([$user->email, $user->password, $user->uuid, $user->token] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode([$first, $page]));
        }
        foreach (['global_coverage_complete', 'projection_applied', 'migration_gate_open'] as $flag) $this->assertFalse($page[$flag]);
    }

    public function test_disabled_production_retains_single_native_update_and_zero_capture_reads(): void
    {
        $user = $this->user(); $server = $this->server(); $this->app['env'] = 'production';
        config(['legacy_capture_shadow.enabled' => false, 'core_shadow.isolated_copy' => false]);
        Schema::drop('tx_legacy_capture_shadow_event'); Schema::drop('tx_legacy_capture_shadow_stream');
        DB::enableQueryLog(); DB::flushQueryLog(); $this->job($server, [$user->id => [2, 4]], 1.5)->handle();
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^select|tx_legacy_capture_shadow|tx_legacy_subscription_map/i', $query['query']);
        }
        $this->assertSame([13, 26], [(int) $user->fresh()->u, (int) $user->fresh()->d]);
    }

    public function test_empty_invalid_and_missing_user_paths_skip_capture_configuration(): void
    {
        $server = $this->server(); config(['legacy_capture_shadow.enabled' => 'invalid']);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->job($server, [])->handle(); $this->job($server, [1 => [1, 2]], -1)->handle();
        $this->job($server, [2147483647 => [1, 2]])->handle();
        $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($q) =>
            preg_match('/tx_legacy_capture_shadow|tx_legacy_subscription_map/', $q['query']))));
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
    }

    public function test_later_user_failure_preserves_earlier_native_commit_without_manufacturing_batch_atomicity(): void
    {
        $first = $this->user(); $second = $this->user(); $server = $this->server(); $this->backfill($first);
        $initial = $this->source($second); $insert = 0; $inject = true;
        DB::listen(function ($query) use (&$insert, &$inject): void {
            if ($inject && preg_match('/^insert.*tx_legacy_capture_shadow_event/i', $query->sql) && ++$insert === 2) {
                throw new \RuntimeException('fetch-fixture-private-storage-detail');
            }
        });
        Redis::shouldReceive('sadd')->never();
        $this->assertFailure('CAPTURE_STORAGE_OR_EVIDENCE_FAILED', fn () => $this->job($server,
            [$first->id => [1, 2], $second->id => [3, 4]])->handle());
        $this->assertSame([11, 22], [(int) $first->fresh()->u, (int) $first->fresh()->d]);
        $this->assertSame($initial, $this->source($second));
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertTrue($this->capture()->verify($first->id)['page_ok']); $inject = false;
    }

    public function test_native_fractional_sql_assignment_is_preserved_or_refused_without_repricing(): void
    {
        $user = $this->user(); $server = $this->server();
        config(['legacy_capture_shadow.enabled' => false]); $this->job($server, [$user->id => [1, 1]], 1.5)->handle();
        $native = DB::table('tx_user')->where('id', $user->id)->first(['u', 'd']);
        DB::table('tx_user')->where('id', $user->id)->update(['u' => 10, 'd' => 20]);
        config(['legacy_capture_shadow.enabled' => true]);
        if ($this->mysql()) {
            $this->job($server, [$user->id => [1, 1]], 1.5)->handle();
            $this->assertEquals($native, DB::table('tx_user')->where('id', $user->id)->first(['u', 'd']));
            $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
        } else {
            $this->assertSame(11.5, $native->u);
            $this->assertFailure('CAPTURE_SOURCE_INVALID', fn () => $this->job($server, [$user->id => [1, 1]], 1.5)->handle());
            $this->assertSame([10, 20], [(int) $user->fresh()->u, (int) $user->fresh()->d]);
            $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        }
    }

    public function test_strict_guards_source_gap_and_bad_queued_reference_roll_back_this_user(): void
    {
        $user = $this->user(); $server = $this->server(); $this->job($server, [$user->id => [1, 2]])->handle();
        DB::table('tx_user')->where('id', $user->id)->increment('d'); $before = $this->source($user);
        $this->assertFailure('CAPTURE_SOURCE_GAP', fn () => $this->job($server, [$user->id => [1, 2]])->handle());
        $this->assertSame($before, $this->source($user));
        $this->assertFailure('CAPTURE_SOURCE_INVALID', fn () =>
            (new TrafficFetchJob(['rate' => 1, 'id' => '1.5'], [$user->id => [1, 2]], 'vmess', 1700000000))->handle());
        config(['legacy_capture_shadow.enabled' => 'true']);
        $this->assertFailure('CAPTURE_ISOLATED_COPY_REQUIRED', fn () => $this->job($server, [$user->id => [1, 2]])->handle());
        $this->assertSame($before, $this->source($user));
    }

    public function test_identified_and_unidentified_chain_keeps_distinct_policies_and_readonly_recovery(): void
    {
        $user = $this->user(); $server = $this->server();
        $batch = fn ($id) => new TrafficBatchJob(['id' => $server->id, 'rate' => 1], [$user->id => [1, 2]], 'vmess', 1700000000, $id);
        $batch('before-fetch')->handle(); $first = DB::table('tx_legacy_capture_shadow_event')->first();
        $this->job($server, [$user->id => [3, 4]])->handle(); $batch('after-fetch')->handle();
        $this->assertEquals($first, DB::table('tx_legacy_capture_shadow_event')->first());
        DB::enableQueryLog(); DB::flushQueryLog(); $page = $this->capture()->verify($user->id);
        $this->assertTrue($page['page_ok']);
        $this->assertSame(['traffic.batch.settle', 'traffic.fetch.apply', 'traffic.batch.settle'], array_column($page['events'], 'source_kind'));
        foreach (DB::getQueryLog() as $query) $this->assertMatchesRegularExpression('/^select/i', $query['query']);
    }

    public function test_queue_failure_uses_fixed_diagnostics_with_original_no_retry_policy(): void
    {
        $user = $this->user(); $server = $this->server(); $job = $this->job($server, [$user->id => [1, 2]]);
        config(['legacy_capture_shadow.currency' => 'invalid']);
        try { $job->handle(); $this->fail('Accepted invalid capture context.'); }
        catch (LegacyCaptureShadowException $exception) { report($exception); $job->failed($exception); }
        $records = $this->log->getRecords(); $this->assertCount(1, $records);
        $this->assertSame('LEGACY_TRAFFIC_FETCH_CAPTURE_FAILED', $records[0]['message']); $this->assertSame([], $records[0]['context']);
        $this->assertSame([1, 20], [$job->tries, $job->timeout]);
        $job->failed(new \RuntimeException('native failure')); $this->assertCount(1, $this->log->getRecords());
    }

    public function test_independent_mysql_same_input_is_two_native_commits_and_contiguous_evidence(): void
    {
        $this->requireFork(); $user = $this->user(); $server = $this->server();
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 10); fread($child, 1); fwrite($child, 'R');
            try { $this->job($server, [$user->id => [1, 2]])->handle(); fwrite($child, 'OK'); }
            catch (\Throwable) { fwrite($child, 'FAIL'); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try {
            DB::beginTransaction(); $this->job($server, [$user->id => [1, 2]])->handle();
            fwrite($parent, 'S'); $this->assertSame('R', fread($parent, 1));
            $read = [$parent]; $write = []; $except = []; $this->assertSame(0, stream_select($read, $write, $except, 0, 250000));
            DB::commit(); $this->assertSame('OK', stream_get_contents($parent, 32));
        } finally { if (DB::transactionLevel()) DB::rollBack(); fclose($parent); pcntl_waitpid($pid, $status); }
        $this->assertSame(0, pcntl_wexitstatus($status)); $this->assertSame(2, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertSame([12, 24], [(int) $user->fresh()->u, (int) $user->fresh()->d]);
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    public function test_mysql_sigkill_current_user_transaction_and_unknown_replay_semantics(): void
    {
        $this->requireFork(); $user = $this->user(); $server = $this->server(); $this->backfill($user); $before = $this->source($user);
        $this->killFetch($user, $server, false); $this->assertSame($before, $this->source($user));
        $this->assertSame(0, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->killFetch($user, $server, true); $this->assertSame([11, 22], [(int) $user->fresh()->u, (int) $user->fresh()->d]);
        $this->assertSame(1, DB::table('tx_legacy_capture_shadow_event')->count());
        // There is no original operation ID: a replay is deliberately another charge.
        $this->job($server, [$user->id => [1, 2]])->handle();
        $this->assertSame([12, 24], [(int) $user->fresh()->u, (int) $user->fresh()->d]);
        $this->assertSame(2, DB::table('tx_legacy_capture_shadow_event')->count());
        $this->assertTrue($this->capture()->verify($user->id)['page_ok']);
    }

    private function killFetch(User $user, Server $server, bool $afterCommit): void
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); $barrier = function () use ($child): void { fwrite($child, 'K'); while (true) usleep(10000); };
            if ($afterCommit) {
                Event::listen(TransactionCommitted::class, function ($event) use ($barrier): void {
                    if ($event->connection->transactionLevel() === 0) $barrier();
                });
            } else {
                DB::listen(function ($query) use ($barrier): void {
                    if (preg_match('/^update.*tx_legacy_subscription_map/i', $query->sql)) $barrier();
                });
            }
            $this->job($server, [$user->id => [1, 2]])->handle(); exit(2);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 10);
        try { $this->assertSame('K', fread($parent, 1)); }
        finally { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); fclose($parent);
            $this->assertTrue(pcntl_wifsignaled($status)); $this->assertSame(SIGKILL, pcntl_wtermsig($status)); }
    }

    private function job(Server $server, array $data, int|float $rate = 1): TrafficFetchJob
    { return new TrafficFetchJob(['id' => $server->id, 'rate' => $rate], $data, 'vmess', 1700000000); }
    private function user(): User
    {
        $id = ++$this->counter;
        return User::create(['email' => 'fetch-capture-'.$id.'@example.test', 'password' => 'fetch-fixture-password',
            'uuid' => sprintf('60000000-0000-4000-8000-%012d', $id), 'token' => sprintf('%032d', $id),
            'transfer_enable' => 1000, 'u' => 10, 'd' => 20, 'balance' => 100, 'expired_at' => time() + 86400]);
    }
    private function server(): Server
    { return Server::create(['name' => 'fetch-node', 'type' => Server::TYPE_VMESS, 'host' => '127.0.0.1', 'port' => '443',
        'server_port' => 443, 'rate' => 1, 'group_ids' => [1], 'enabled' => true]); }
    private function capture(): LegacyUserMutationCapture { return app(LegacyUserMutationCapture::class); }
    private function backfill(User $user): void
    { app(SubscriptionShadowBackfill::class)->run('fetch-map-'.$user->id, 100, app(LegacySubscriptionSnapshot::class)->context('UTC', 'USD')); }
    private function source(User $user): array
    {
        $row = (array) DB::table('tx_user')->where('id', $user->id)->select(LegacyUserMutationCapture::FIELDS)->first();
        foreach ($row as $key => $value) $row[$key] = $key === 'banned' ? (bool) $value : ($value === null ? null : (string) $value);
        ksort($row, SORT_STRING); return $row;
    }
    private function projection(): array
    {
        $out = []; foreach (['tx_subscription', 'tx_subscription_terms', 'tx_usage_period', 'tx_usage_adjustment',
            'tx_access_subject', 'tx_commerce_shadow_money_journal', 'tx_commerce_shadow_money_posting'] as $name)
            $out[$name] = DB::table($name)->orderBy('id')->get()->toJson(); return $out;
    }
    private function assertFailure(string $code, callable $callback): void
    {
        try { $callback(); $this->fail('Accepted invalid unidentified traffic evidence.'); }
        catch (LegacyCaptureShadowException $exception) { $this->assertSame($code, $exception->getMessage()); $this->assertNull($exception->getPrevious()); }
    }
    private function mysql(): bool { return getenv('CORE_CAPTURE_TEST_MYSQL') === '1'; }
    private function connection(): string { return $this->mysql() ? 'traffic_fetch_capture_mysql_testing' : 'traffic_fetch_capture_sqlite_testing'; }
    private function requireFork(): void
    { if (!$this->mysql() || !function_exists('pcntl_fork') || !function_exists('posix_kill')) $this->markTestSkipped('Requires independent MariaDB sessions and process control.'); }
    private function clearMysql(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') throw new \RuntimeException('Fetch capture needs its dedicated database.');
        Schema::disableForeignKeyConstraints(); try { foreach (Schema::getTableListing() as $table) Schema::drop($table); }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
