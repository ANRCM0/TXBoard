<?php

namespace Tests\Feature\Server;

use App\Domains\Network\NativeNodeReport;
use App\Domains\Network\NativeTrafficPeriod;
use App\Domains\Network\NativeTrafficReceipt;
use App\Domains\Network\NativeTrafficSegments;
use App\Domains\Network\NodeReportError;
use App\Jobs\TrafficBatchJob;
use App\Models\Plan;
use App\Models\Server;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TrafficResetService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

class NativeTrafficPeriodSettlementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.native_traffic_period_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'database.default' => 'native_traffic_period_fixture', 'native_traffic_receipts.enabled' => true,
            'cache.default' => 'array', 'setting.cache_store' => 'array']);
        DB::purge('native_traffic_period_fixture');
        $this->artisan('migrate:fresh', ['--database' => 'native_traffic_period_fixture', '--force' => true])->assertExitCode(0);
        Bus::fake();
        Redis::shouldReceive('sadd')->zeroOrMoreTimes()->andReturn(1);
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function fixture(int $epoch = 0): array
    {
        $plan = Plan::withoutEvents(fn () => Plan::create(['name' => 'period-fixture', 'group_id' => 1, 'transfer_enable' => 1000,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY]));
        $user = User::withoutEvents(fn () => User::create(['email' => 'period-fixture@example.test', 'password' => 'test', 'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'token' => str_repeat('a', 32), 'group_id' => 1, 'plan_id' => $plan->id, 'expired_at' => time() + 86400 * 120,
            'u' => 0, 'd' => 0, 'transfer_enable' => 1000, 'reset_count' => $epoch]));
        $node = Server::withoutEvents(fn () => Server::create(['name' => 'period-node', 'type' => 'vless', 'host' => '127.0.0.1', 'port' => 24443, 'server_port' => 24443,
            'group_ids' => ['1'], 'rate' => 1.5, 'enabled' => true, 'protocol_settings' => ['tls' => 0, 'network' => 'tcp']]));
        return [$node, $user];
    }

    private function segment(User $user, int $epoch, int $up, int $down): array
    {
        return ['user_id' => $user->id, 'period' => app(NativeTrafficPeriod::class)->issue($user->id, $epoch), 'upload' => $up, 'download' => $down];
    }

    private function submit(Server $node, User $user, string $batch, int $up, int $down, ?array $segments): array
    {
        $body = ['protocol_version' => 1, 'traffic_batch_id' => $batch, 'traffic' => [$user->id => [$up, $down]]];
        if ($segments !== null) $body['traffic_segments'] = $segments;
        return app(NativeNodeReport::class)->submit($node, $body);
    }

    private function period(User $user, int $epoch): object
    {
        return DB::table('tx_native_traffic_period_usage')->where(['user_id' => $user->id, 'reset_count' => $epoch])->first();
    }

    public function test_delayed_pre_reset_queue_input_settles_history_without_consuming_new_period(): void
    {
        [$node, $user] = $this->fixture();
        $ack = $this->submit($node, $user, 'period-delayed-001', 5, 9, [$this->segment($user, 0, 5, 9)]);
        $this->assertSame(0, DB::table('tx_traffic_batch')->count());
        $this->assertTrue(app(TrafficResetService::class)->performReset($user, TrafficResetLog::SOURCE_MANUAL));
        $this->assertSame(1, (int) $user->fresh()->reset_count);
        Bus::dispatched(TrafficBatchJob::class)->first()->handle();
        $this->assertSame([0, 0], [(int) $user->fresh()->u, (int) $user->fresh()->d]);
        $history = $this->period($user, 0);
        $this->assertSame([5, 9, 7, 13], [(int) $history->raw_upload, (int) $history->raw_download, (int) $history->billed_upload, (int) $history->billed_download]);
        $this->assertSame([5, 9], [(int) $node->fresh()->u, (int) $node->fresh()->d]);
        $this->assertSame([7, 13], [(int) DB::table('tx_stat_user')->first()->u, (int) DB::table('tx_stat_user')->first()->d]);
        $before = (array) $history;
        app(NativeTrafficReceipt::class)->recover((int) $ack['receipt_id']);
        $this->assertSame($before, (array) $this->period($user, 0));
        $this->assertSame(1, DB::table('tx_traffic_batch')->count());
        $fresh = $this->submit($node, $user, 'period-current-001', 3, 4, [$this->segment($user, 1, 3, 4)]);
        app(NativeTrafficReceipt::class)->recover((int) $fresh['receipt_id']);
        $this->assertSame([4, 6], [(int) $user->fresh()->u, (int) $user->fresh()->d]);
    }

    public function test_old_offline_period_first_received_after_reset_and_mixed_segments_have_exact_rounding(): void
    {
        [$node, $user] = $this->fixture(2);
        $segments = [$this->segment($user, 2, 1, 3), $this->segment($user, 1, 1, 1)];
        $ack = $this->submit($node, $user, 'period-mixed-001', 2, 4, $segments);
        app(NativeTrafficReceipt::class)->recover((int) $ack['receipt_id']);
        // Segment floor: floor1.5+floor1.5=2, not floor(2*1.5)=3.
        $this->assertSame([1, 4], [(int) $user->fresh()->u, (int) $user->fresh()->d]);
        $this->assertSame([1, 1], [(int) $this->period($user, 1)->billed_upload, (int) $this->period($user, 1)->billed_download]);
        $this->assertSame([2, 5], [(int) DB::table('tx_stat_user')->first()->u, (int) DB::table('tx_stat_user')->first()->d]);
        $this->assertSame([2, 4], [(int) $node->fresh()->u, (int) $node->fresh()->d]);
    }

    public function test_period_merge_and_order_are_canonical_but_changed_period_identity_conflicts(): void
    {
        [$node, $user] = $this->fixture(1);
        $one = $this->segment($user, 0, 2, 3); $two = $this->segment($user, 0, 3, 4);
        $ack = $this->submit($node, $user, 'period-canonical-001', 5, 7, [$two, $one]);
        $row = DB::table('tx_native_traffic_receipt')->where('id', $ack['receipt_id'])->first();
        $replay = $this->submit($node, $user, 'period-canonical-001', 5, 7, [$this->segment($user, 0, 5, 7)]);
        $this->assertSame($ack['receipt_id'], $replay['receipt_id']);
        $this->assertEquals($row, DB::table('tx_native_traffic_receipt')->where('id', $ack['receipt_id'])->first());
        try { $this->submit($node, $user, 'period-canonical-001', 5, 7, [$this->segment($user, 1, 5, 7)]); $this->fail('changed token under same batch accepted'); }
        catch (NodeReportError $error) { $this->assertSame('TRAFFIC_BATCH_CONFLICT', $error->errorCode); }
        $legacy = $this->submit($node, $user, 'period-legacy-identity', 5, 7, null);
        try { $this->submit($node, $user, 'period-legacy-identity', 5, 7, [$this->segment($user, 1, 5, 7)]); $this->fail('old batch silently relabeled'); }
        catch (NodeReportError $error) { $this->assertSame('TRAFFIC_BATCH_CONFLICT', $error->errorCode); }
        $this->assertNotSame($ack['receipt_id'], $legacy['receipt_id']);
    }

    public function test_malformed_future_wrong_uid_and_sum_mismatch_are_rejected_before_receipt(): void
    {
        [$node, $user] = $this->fixture(1);
        $cases = [
            'future' => [$this->segment($user, 2, 1, 2)],
            'wrong-uid' => [['user_id' => $user->id, 'period' => app(NativeTrafficPeriod::class)->issue($user->id + 1, 1), 'upload' => 1, 'download' => 2]],
            'bad-signature' => [['user_id' => $user->id, 'period' => 'p1.'.$user->id.'.1.'.str_repeat('a', 64), 'upload' => 1, 'download' => 2]],
            'missing' => [],
            'sum' => [$this->segment($user, 1, 1, 1)],
            'extra-field' => [$this->segment($user, 1, 1, 2) + ['unknown' => 1]],
            'partial' => [['user_id' => $user->id, 'period' => 'partial', 'upload' => 1]],
        ];
        foreach ($cases as $name => $segments) {
            try { $this->submit($node, $user, 'invalid-period-'.$name, 1, 2, $segments); $this->fail('bad period accepted: '.$name); }
            catch (NodeReportError $error) { $this->assertContains($error->errorCode, ['INVALID_TRAFFIC_PERIOD', 'INVALID_TRAFFIC_SEGMENTS']); }
        }
        $this->assertSame(0, DB::table('tx_native_traffic_receipt')->count()); Bus::assertNotDispatched(TrafficBatchJob::class);
    }

    public function test_hooks_may_change_rate_but_cannot_change_attributed_raw_user_bytes(): void
    {
        [$node, $user] = $this->fixture();
        HookManager::registerFilter('traffic.process.before', function ($prepared): array { $prepared[0]['rate'] = 2; return $prepared; });
        $ack = $this->submit($node, $user, 'period-rate-hook', 1, 2, [$this->segment($user, 0, 1, 2)]);
        app(NativeTrafficReceipt::class)->recover((int) $ack['receipt_id']);
        $this->assertSame([2, 4], [(int) $user->fresh()->u, (int) $user->fresh()->d]);
        HookManager::registerFilter('traffic.before_process', function ($prepared) use ($user): array { $prepared[2] = [$user->id => [9, 9]]; return $prepared; });
        try { $this->submit($node, $user, 'period-attribution-hook', 1, 2, [$this->segment($user, 0, 1, 2)]); $this->fail('hook guessed period attribution'); }
        catch (NodeReportError $error) { $this->assertSame('TRAFFIC_PERIOD_HOOK_CONFLICT', $error->errorCode); }
        $this->assertSame(1, DB::table('tx_native_traffic_receipt')->count());
    }

    public function test_period_overflow_rolls_back_ledger_current_user_and_all_statistics(): void
    {
        [$node, $user] = $this->fixture();
        DB::table('tx_native_traffic_period_usage')->insert(['user_id' => $user->id, 'reset_count' => 0, 'raw_upload' => PHP_INT_MAX - 1, 'raw_download' => 0,
            'billed_upload' => 0, 'billed_download' => 0, 'updated_at' => time()]);
        $ack = $this->submit($node, $user, 'period-overflow-001', 5, 9, [$this->segment($user, 0, 5, 9)]);
        try { app(NativeTrafficReceipt::class)->recover((int) $ack['receipt_id']); $this->fail('overflow committed'); }
        catch (RuntimeException $error) { $this->assertSame('Traffic period counter overflow', $error->getMessage()); }
        $this->assertSame(0, DB::table('tx_traffic_batch')->count());
        $this->assertSame([0, 0], [(int) $user->fresh()->u, (int) $user->fresh()->d]);
        $this->assertSame([0, 0], [(int) $node->fresh()->u, (int) $node->fresh()->d]);
        $this->assertSame(0, DB::table('tx_stat_user')->count()); $this->assertSame(0, DB::table('tx_stat_server')->count());
        $this->assertSame(PHP_INT_MAX - 1, (int) $this->period($user, 0)->raw_upload);
    }

    public function test_segment_total_bound_rejects_overflow_and_invalid_scalar_shapes(): void
    {
        [$node, $user] = $this->fixture();
        $token = app(NativeTrafficPeriod::class)->issue($user->id, 0);
        $cases = [
            [['user_id' => $user->id, 'period' => $token, 'upload' => 1125899906842624, 'download' => 0], ['user_id' => $user->id, 'period' => $token, 'upload' => 1, 'download' => 0]],
            [['user_id' => $user->id, 'period' => $token, 'upload' => 1.5, 'download' => 0]],
            [['user_id' => $user->id, 'period' => $token, 'upload' => -1, 'download' => 0]],
            [['user_id' => $user->id, 'period' => $token, 'upload' => '01', 'download' => 0]],
        ];
        foreach ($cases as $segments) {
            try { NativeTrafficSegments::canonical($segments, [$user->id => [1, 0]]); $this->fail('invalid segment accepted'); }
            catch (NodeReportError $error) { $this->assertSame('INVALID_TRAFFIC_SEGMENTS', $error->errorCode); }
        }
    }

    public function test_legacy_hash_and_arrival_period_semantics_remain_explicit_and_unchanged(): void
    {
        [$node, $user] = $this->fixture();
        $ack = $this->submit($node, $user, 'period-old-spool-001', 5, 9, null);
        $row = DB::table('tx_native_traffic_receipt')->where('id', $ack['receipt_id'])->first();
        $input = json_decode($row->settlement_input, true);
        $this->assertArrayNotHasKey('segments', $input);
        $this->assertSame(hash('sha256', json_encode([$input['server']['rate'], $input['data']], JSON_THROW_ON_ERROR)), $row->ledger_payload_hash);
        $this->assertTrue(app(TrafficResetService::class)->performReset($user, TrafficResetLog::SOURCE_MANUAL));
        app(NativeTrafficReceipt::class)->recover((int) $ack['receipt_id']);
        $this->assertSame([7, 13], [(int) $user->fresh()->u, (int) $user->fresh()->d]);
        $this->assertSame(0, DB::table('tx_native_traffic_period_usage')->count());
    }

    public function test_missing_user_keeps_original_skip_without_fabricating_period_history(): void
    {
        [$node, $user] = $this->fixture();
        $ack = $this->submit($node, $user, 'period-deleted-user', 1, 2, [$this->segment($user, 0, 1, 2)]);
        User::withoutEvents(fn () => $user->delete());
        app(NativeTrafficReceipt::class)->recover((int) $ack['receipt_id']);
        $this->assertSame(1, DB::table('tx_traffic_batch')->count());
        $this->assertSame(0, DB::table('tx_native_traffic_period_usage')->count());
        $this->assertSame([0, 0], [(int) $node->fresh()->u, (int) $node->fresh()->d]);
    }
    public function test_native_http_users_emit_current_period_and_negotiated_capability(): void
    {
        [$node, $user] = $this->fixture(1);
        admin_setting(['server_token' => 'period-native-http-fixture']);
        config(['native_traffic_periods.enabled' => true]);
        $headers = ['Authorization' => 'Bearer period-native-http-fixture', 'X-TX-Node-ID' => (string) $node->id];
        $capabilities = $this->postJson('/txapi/node/v1/handshake', [], $headers)->assertOk()->json('data.capabilities');
        $this->assertContains('traffic_period_v1', $capabilities);
        $first = $this->getJson('/txapi/node/v1/users', $headers)->assertOk();
        $token = $first->json('data.users.0.traffic_period');
        $this->assertSame(app(NativeTrafficPeriod::class)->issue($user->id, 1), $token);
        $etag = $first->headers->get('ETag');
        $this->assertTrue(app(TrafficResetService::class)->performReset($user, TrafficResetLog::SOURCE_MANUAL));
        $updated = $this->getJson('/txapi/node/v1/users', $headers + ['If-None-Match' => $etag])->assertOk();
        $this->assertNotSame($etag, $updated->headers->get('ETag'));
        $this->assertSame(app(NativeTrafficPeriod::class)->issue($user->id, 2), $updated->json('data.users.0.traffic_period'));
        config(['native_traffic_receipts.enabled' => false]);
        $legacyCaps = $this->postJson('/txapi/node/v1/handshake', [], $headers)->assertOk()->json('data.capabilities');
        $this->assertNotContains('traffic_period_v1', $legacyCaps);
        try { $this->submit($node, $user, 'period-legacy-disabled', 1, 2, [$this->segment($user, 2, 1, 2)]); $this->fail('period input lost through legacy queued path'); }
        catch (NodeReportError $error) { $this->assertSame('TRAFFIC_DURABILITY_UNAVAILABLE', $error->errorCode); }
    }

    public function test_late_period_user_statistics_overflow_rolls_back_every_settlement_effect(): void
    {
        $this->assertLateStatisticsOverflow('tx_stat_user', 'User traffic statistic overflow');
    }

    public function test_late_period_node_statistics_overflow_rolls_back_every_settlement_effect(): void
    {
        $this->assertLateStatisticsOverflow('tx_stat_server', 'Server traffic statistic overflow');
    }

    private function assertLateStatisticsOverflow(string $table, string $errorCode): void
    {
        [$node, $user] = $this->fixture(1);
        $ack = $this->submit($node, $user, 'late-stat-overflow-'.($table === 'tx_stat_user' ? 'user' : 'node'), 5, 9, [$this->segment($user, 0, 5, 9)]);
        $receipt = DB::table('tx_native_traffic_receipt')->where('id', $ack['receipt_id'])->first();
        $input = json_decode($receipt->settlement_input, true);
        $common = ['u' => PHP_INT_MAX - 1, 'd' => PHP_INT_MAX - 1, 'record_type' => 'd', 'record_at' => $input['record_at'], 'created_at' => time(), 'updated_at' => time()];
        $identity = $table === 'tx_stat_user' ? ['user_id' => $user->id, 'server_rate' => $input['server']['rate']]
            : ['server_id' => $node->id, 'server_type' => $input['protocol']];
        DB::table($table)->insert($identity + $common);
        $statisticsBefore = (array) DB::table($table)->first();
        try { app(NativeTrafficReceipt::class)->recover((int) $ack['receipt_id']); $this->fail('late statistic overflow committed'); }
        catch (RuntimeException $error) { $this->assertSame($errorCode, $error->getMessage()); }
        $this->assertSame(0, DB::table('tx_traffic_batch')->count());
        $this->assertSame(0, DB::table('tx_native_traffic_period_usage')->count());
        $this->assertSame([0, 0], [(int) $user->fresh()->u, (int) $user->fresh()->d]);
        $this->assertSame([0, 0], [(int) $node->fresh()->u, (int) $node->fresh()->d]);
        $this->assertSame($statisticsBefore, (array) DB::table($table)->first());
        $this->assertSame(0, DB::table($table === 'tx_stat_user' ? 'tx_stat_server' : 'tx_stat_user')->count());
        $this->assertEquals($receipt, DB::table('tx_native_traffic_receipt')->where('id', $receipt->id)->first());
        $this->assertSame('pending', $receipt->status);
    }

}
