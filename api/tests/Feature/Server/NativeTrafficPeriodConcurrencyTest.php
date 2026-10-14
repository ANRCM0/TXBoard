<?php

namespace Tests\Feature\Server;

use App\Domains\Network\NativeTrafficPeriod;
use App\Domains\Network\NativeTrafficReceipt;
use App\Jobs\TrafficBatchJob;
use App\Models\Plan;
use App\Models\Server;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\TrafficResetService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/** Opt-in real SQL session ordering; only the explicitly named synthetic database. */
class NativeTrafficPeriodConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('NATIVE_TRAFFIC_PERIOD_MYSQL') !== '1' || !function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Requires the exclusive native-period MySQL fixture and independent processes.');
        }
        $password = getenv('NATIVE_TRAFFIC_PERIOD_PASSWORD');
        if (!is_string($password) || $password === '') throw new \RuntimeException('NATIVE_PERIOD_FIXTURE_PASSWORD_REQUIRED');
        config(['database.connections.native_traffic_period_mysql_fixture' => ['driver' => 'mysql', 'host' => '127.0.0.1',
            'port' => getenv('NATIVE_TRAFFIC_PERIOD_PORT') ?: '13306', 'database' => 'txboard_native_period_test',
            'username' => 'txboard_native_period', 'password' => $password, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true],
            'database.default' => 'native_traffic_period_mysql_fixture', 'native_traffic_receipts.enabled' => true, 'cache.default' => 'array']);
        DB::purge('native_traffic_period_mysql_fixture');
        $this->assertSame('txboard_native_period_test', DB::connection()->getDatabaseName());
        $this->assertSame('txboard_native_period', DB::connection()->getConfig('username'));
        $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        // Opt-in fixture owner provisions and backs up this exact DB first.
        $this->artisan('migrate:fresh', ['--database' => 'native_traffic_period_mysql_fixture', '--force' => true])->assertExitCode(0);
        Bus::fake(); Redis::shouldReceive('sadd')->zeroOrMoreTimes()->andReturn(1);
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function fixture(): array
    {
        $plan = Plan::withoutEvents(fn () => Plan::create(['name' => 'concurrent-period', 'group_id' => 1, 'transfer_enable' => 1000, 'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY]));
        $user = User::withoutEvents(fn () => User::create(['email' => 'period-concurrency@example.test', 'password' => 'test', 'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'token' => str_repeat('a', 32), 'group_id' => 1, 'plan_id' => $plan->id, 'expired_at' => time() + 86400 * 120, 'u' => 0, 'd' => 0, 'transfer_enable' => 1000, 'reset_count' => 0]));
        $node = Server::withoutEvents(fn () => Server::create(['name' => 'concurrent-period', 'type' => 'vless', 'host' => '127.0.0.1', 'port' => 24443, 'server_port' => 24443,
            'group_ids' => ['1'], 'rate' => 1.5, 'enabled' => true, 'protocol_settings' => ['tls' => 0, 'network' => 'tcp']]));
        $receipt = app(NativeTrafficReceipt::class)->accept($node, 'concurrent-period-001', [$user->id => [5, 9]], [
            ['user_id' => $user->id, 'period' => app(NativeTrafficPeriod::class)->issue($user->id, 0), 'upload' => 5, 'download' => 9],
        ]);
        return [$node, $user, $receipt];
    }

    private function blockedChild(User $user, callable $parentPhase, callable $childPhase): void
    {
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        // Fork without an inherited live PDO; parent and child reconnect separately.
        DB::purge(); $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 15); fread($child, 1); fwrite($child, 'R');
            try { $childPhase(); fwrite($child, 'OK'); }
            catch (\Throwable $error) { fwrite($child, 'FAIL:'.$error::class); }
            fclose($child); DB::disconnect(); exit(0);
        }
        $this->assertGreaterThan(0, $pid); fclose($child); stream_set_timeout($parent, 15);
        try {
            DB::beginTransaction(); DB::table('tx_user')->where('id', $user->id)->lockForUpdate()->first();
            $parentPhase(); fwrite($parent, 'S'); $this->assertSame('R', fread($parent, 1));
            $read = [$parent]; $write = []; $except = [];
            $this->assertSame(0, stream_select($read, $write, $except, 0, 250000), 'Child escaped the locked User period boundary.');
            DB::commit(); $this->assertSame('OK', stream_get_contents($parent, 128));
        } finally {
            if (DB::transactionLevel() > 0) DB::rollBack(0);
            fclose($parent); pcntl_waitpid($pid, $status);
        }
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    public function test_mysql_reset_wins_user_lock_and_waiting_period_job_rereads_new_epoch(): void
    {
        [$node, $user, $receipt] = $this->fixture();
        $this->blockedChild($user,
            function () use ($user): void { $this->assertTrue(app(TrafficResetService::class)->performReset($user, TrafficResetLog::SOURCE_MANUAL)); },
            fn () => app(NativeTrafficReceipt::class)->recover((int) $receipt->id));
        $fresh = $user->fresh();
        $this->assertSame(1, (int) $fresh->reset_count); $this->assertSame([0, 0], [(int) $fresh->u, (int) $fresh->d]);
        $history = DB::table('tx_native_traffic_period_usage')->first();
        $this->assertSame([0, 5, 9, 7, 13], [(int) $history->reset_count, (int) $history->raw_upload, (int) $history->raw_download, (int) $history->billed_upload, (int) $history->billed_download]);
        $this->assertSame(1, DB::table('tx_traffic_batch')->count());
        $this->assertSame('settled', DB::table('tx_native_traffic_receipt')->value('status'));
    }

    public function test_mysql_settlement_wins_user_lock_then_waiting_reset_clears_current_only(): void
    {
        [$node, $user, $receipt] = $this->fixture(); $input = json_decode($receipt->settlement_input, true);
        $this->blockedChild($user,
            fn () => (new TrafficBatchJob($input['server'], $input['data'], $input['protocol'], $input['record_at'], $receipt->batch_id, null, $input['segments']))->handle(),
            function () use ($user): void { if (!app(TrafficResetService::class)->performReset($user, TrafficResetLog::SOURCE_MANUAL)) throw new \RuntimeException('RESET_FAILED'); });
        app(NativeTrafficReceipt::class)->recover((int) $receipt->id);
        $fresh = $user->fresh();
        $this->assertSame(1, (int) $fresh->reset_count); $this->assertSame([0, 0], [(int) $fresh->u, (int) $fresh->d]);
        $this->assertSame(1, DB::table('tx_traffic_batch')->count());
        $this->assertSame(1, DB::table('tx_native_traffic_period_usage')->count());
        $this->assertSame([7, 13], [(int) DB::table('tx_stat_user')->first()->u, (int) DB::table('tx_stat_user')->first()->d]);
        $this->assertSame([5, 9], [(int) $node->fresh()->u, (int) $node->fresh()->d]);
    }
}
