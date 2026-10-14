<?php

namespace Tests\Feature\Server;

use App\Jobs\TrafficBatchJob;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;
use Throwable;

/** Independent sessions expose statistics committed after a real older RR read. */
class NativeTrafficBatchStatisticsConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('NATIVE_TRAFFIC_PERIOD_MYSQL') !== '1' || !function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Requires the exclusive native-period MySQL fixture and independent processes.');
        }
        $password = getenv('NATIVE_TRAFFIC_PERIOD_PASSWORD');
        if (!is_string($password) || $password === '') throw new \RuntimeException('NATIVE_PERIOD_FIXTURE_PASSWORD_REQUIRED');
        config(['database.connections.native_traffic_statistics_mysql_fixture' => ['driver' => 'mysql', 'host' => '127.0.0.1',
            'port' => getenv('NATIVE_TRAFFIC_PERIOD_PORT') ?: '13306', 'database' => 'txboard_native_period_test',
            'username' => 'txboard_native_period', 'password' => $password, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true],
            'database.default' => 'native_traffic_statistics_mysql_fixture', 'authority_writer_fence.enabled' => false,
            'legacy_capture_shadow.enabled' => false, 'cache.default' => 'array']);
        DB::purge('native_traffic_statistics_mysql_fixture');
        $this->assertSame('txboard_native_period_test', DB::connection()->getDatabaseName());
        $this->assertSame('txboard_native_period', DB::connection()->getConfig('username'));
        $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        // The coordinating fixture owner provisions and backs up this exact DB.
        $this->artisan('migrate:fresh', ['--database' => 'native_traffic_statistics_mysql_fixture', '--force' => true])->assertExitCode(0);
        Bus::fake(); Redis::shouldReceive('sadd')->zeroOrMoreTimes()->andReturn(1);
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_mysql_current_statistics_read_ignores_prior_repeatable_read_snapshot(): void
    {
        $recordAt = strtotime(date('Y-m-d'));
        $users = $statistics = $traffic = [];
        for ($id = 1; $id <= 401; ++$id) {
            $users[] = ['id' => $id, 'email' => "statistic-chunk-{$id}@example.test", 'password' => 'synthetic-password',
                'uuid' => sprintf('550e8400-e29b-41d4-a716-%012d', $id), 'token' => sprintf('%032d', $id),
                'u' => 0, 'd' => 0, 'transfer_enable' => 10000, 'created_at' => time(), 'updated_at' => time()];
            $traffic[$id] = [1, 2];
            // The 401st statistic is absent in A's repeatable-read snapshot,
            // then committed by the independent original settlement job.
            if ($id <= 400) $statistics[] = ['user_id' => $id, 'server_rate' => 1, 'record_at' => $recordAt,
                'record_type' => 'd', 'u' => 0, 'd' => 0, 'created_at' => time(), 'updated_at' => time()];
        }
        foreach (array_chunk($users, 100) as $chunk) DB::table('tx_user')->insert($chunk);
        foreach (array_chunk($statistics, 100) as $chunk) DB::table('tx_stat_user')->insert($chunk);
        $node = static fn (string $name): Server => Server::withoutEvents(fn () => Server::create(['name' => $name, 'type' => 'vless', 'host' => '127.0.0.1',
            'port' => 24443, 'server_port' => 24443, 'group_ids' => ['1'], 'rate' => 1, 'enabled' => true]));
        $left = $node('statistics-chunk-left'); $right = $node('statistics-chunk-right');
        $this->assertSame(400, DB::table('tx_stat_user')->count());
        $this->assertSame(0, DB::table('tx_stat_user')->where('user_id', 401)->count());
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        DB::purge();
        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 20);
            try {
                DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                fwrite($child, 'R');
                if (fread($child, 1) !== 'S') throw new \RuntimeException('STATISTIC_FIXTURE_SIGNAL_MISSING');
                (new TrafficBatchJob(['id' => $right->id, 'rate' => 1], [401 => [3, 4]], 'vless', $recordAt, 'statistics-chunk-right-001'))->handle();
                fwrite($child, 'OK');
            } catch (Throwable $error) {
                if (DB::transactionLevel() > 0) DB::rollBack(0);
                fwrite($child, 'FAIL:'.$error::class);
            }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 20);
        $released = false; $leftError = null; $rightResult = '';
        try {
            $this->assertSame('R', fread($parent, 1));
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::beginTransaction();
            // A real consistent read establishes an older RR view. An existing
            // outer native transaction can have read before waiting on Users;
            // the job's current reads must not inherit this stale statistic view.
            $this->assertNull(DB::table('tx_stat_user')->where('user_id', 401)->first());
            fwrite($parent, 'S'); $released = true;
            // A holds no User/statistic range locks while B commits, so the
            // fixture does not manufacture a lock-order deadlock via callbacks.
            $rightResult = stream_get_contents($parent, 128);
            try {
                (new TrafficBatchJob(['id' => $left->id, 'rate' => 1], $traffic, 'vless', $recordAt, 'statistics-chunk-left-001'))->handle();
                DB::commit();
            } catch (Throwable $error) { $leftError = $error; }
        } finally {
            if (!$released) fwrite($parent, 'S');
            if (DB::transactionLevel() > 0) DB::rollBack(0);
            fclose($parent); pcntl_waitpid($pid, $status);
        }
        $this->assertSame('OK', $rightResult, 'The independent statistic writer did not commit.');
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertNull($leftError, 'Batch failed after a concurrent statistic commit: '.($leftError === null ? '' : $leftError::class.' code='.$leftError->getCode()));
        $this->assertSame(2, DB::table('tx_traffic_batch')->count());
        $this->assertSame(401, DB::table('tx_stat_user')->count());
        $this->assertSame(1, DB::table('tx_stat_user')->where('user_id', 401)->count());
        $user = DB::table('tx_user')->where('id', 401)->first();
        $stat = DB::table('tx_stat_user')->where('user_id', 401)->first();
        $this->assertSame([4, 6], [(int) $user->u, (int) $user->d]);
        $this->assertSame([4, 6], [(int) $stat->u, (int) $stat->d]);
        $this->assertSame([404, 806], [(int) DB::table('tx_stat_user')->sum('u'), (int) DB::table('tx_stat_user')->sum('d')]);
        $this->assertSame([401, 802], [(int) $left->fresh()->u, (int) $left->fresh()->d]);
        $this->assertSame([3, 4], [(int) $right->fresh()->u, (int) $right->fresh()->d]);
        $this->assertSame(2, DB::table('tx_stat_server')->count());
    }
}
