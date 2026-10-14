<?php

namespace Tests\Feature\Server;

use App\Domains\Network\NativeNodeReport;
use App\Domains\Network\NativeTrafficReceipt;
use App\Domains\Network\NodeReportError;
use App\Jobs\TrafficBatchJob;
use App\Models\Server;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NativeTrafficReceiptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.native_traffic_receipt_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'database.default' => 'native_traffic_receipt_fixture', 'native_traffic_receipts.enabled' => true,
            'cache.default' => 'array', 'setting.cache_store' => 'array']);
        DB::purge('native_traffic_receipt_fixture');
        $this->artisan('migrate:fresh', ['--database' => 'native_traffic_receipt_fixture', '--force' => true])->assertExitCode(0);
        Bus::fake();
        Redis::shouldReceive('sadd')->zeroOrMoreTimes()->andReturn(1);
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function node(): Server
    {
        return Server::withoutEvents(fn () => Server::create(['name' => 'receipt-node', 'type' => 'vless', 'host' => '127.0.0.1', 'port' => 24443, 'server_port' => 24443,
            'group_ids' => ['1'], 'rate' => 1.5, 'enabled' => true, 'protocol_settings' => ['tls' => 0, 'network' => 'tcp']]));
    }

    private function user(): User
    {
        return User::withoutEvents(fn () => User::create(['email' => 'native-receipt@example.test', 'password' => 'test',
            'uuid' => '550e8400-e29b-41d4-a716-446655440000', 'token' => str_repeat('a', 32), 'u' => 0, 'd' => 0, 'transfer_enable' => 10000]));
    }

    private function submit(Server $node, string $batch, array $traffic): array
    {
        return app(NativeNodeReport::class)->submit($node, ['protocol_version' => 1, 'traffic_batch_id' => $batch, 'traffic' => $traffic]);
    }

    private function row(int|string $id): object
    {
        return DB::table('tx_native_traffic_receipt')->where('id', $id)->first();
    }

    public function test_ack_is_committed_receipt_before_original_job_settles_once(): void
    {
        $node = $this->node(); $user = $this->user();
        $ack = $this->submit($node, 'native-receipt-001', [$user->id => [5, 9]]);
        $this->assertSame('persisted', $ack['receipt_status']); $this->assertSame('queued', $ack['settlement']);
        $this->assertSame(1, Cache::get(CacheKey::get('SERVER_VLESS_ONLINE_USER', $node->id)));
        $this->assertNotNull(Cache::get(CacheKey::get('SERVER_VLESS_LAST_PUSH_AT', $node->id)));
        $this->assertSame(0, DB::transactionLevel());
        $row = $this->row($ack['receipt_id']);
        $this->assertSame('pending', $row->status); $this->assertNull($row->quota_checked_at);
        $this->assertSame(0, DB::table('tx_traffic_batch')->count());
        $this->assertSame(0, (int) $user->fresh()->u);
        $jobs = Bus::dispatched(TrafficBatchJob::class); $this->assertCount(1, $jobs);
        $jobs->first()->handle(); $jobs->first()->handle();
        $this->assertSame(7, (int) $user->fresh()->u); $this->assertSame(13, (int) $user->fresh()->d);
        $this->assertSame(5, (int) $node->fresh()->u); $this->assertSame(9, (int) $node->fresh()->d);
        $this->assertSame(1, DB::table('tx_traffic_batch')->count());
        $this->assertSame('settled', $this->row($ack['receipt_id'])->status);
        $this->assertSame([$user->id], app(NativeTrafficReceipt::class)->settledUserIds($this->row($ack['receipt_id'])));
    }

    public function test_replay_canonical_raw_order_and_scalar_types_do_not_repeat_hooks_or_reselect_rate_day(): void
    {
        $node = $this->node(); $calls = 0;
        HookManager::registerFilter('traffic.process.before', function ($prepared) use (&$calls): array { ++$calls; $prepared[0]['rate'] = 2; return $prepared; });
        $ack = $this->submit($node, 'native-receipt-order', [9 => [3, 4], 7 => [1, 2]]);
        $before = (array) $this->row($ack['receipt_id']);
        $node->update(['rate' => 3]);
        // If replay consulted the preparer, it would now choose a different day.
        $preparer = Mockery::mock(UserService::class); $preparer->shouldNotReceive('prepareTrafficInput'); $this->app->instance(UserService::class, $preparer);
        $replay = $this->submit($node, 'native-receipt-order', ['7' => ['1', '2'], '9' => ['3', '4']]);
        $this->assertSame($ack['receipt_id'], $replay['receipt_id']); $this->assertSame(1, $calls);
        $this->assertSame($before, (array) $this->row($ack['receipt_id']));
        $input = json_decode($before['settlement_input'], true);
        $this->assertSame(2, $input['server']['rate']); $this->assertSame([7, 9], array_keys($input['data']));
        Bus::assertDispatchedTimes(TrafficBatchJob::class, 2);
    }

    public function test_first_post_hook_input_is_frozen_but_receipt_never_contains_credentials(): void
    {
        $node = $this->node(); $user = $this->user();
        HookManager::registerFilter('traffic.process.before', function ($prepared) use ($user): array { $prepared[0]['token'] = 'secret-node-token'; $prepared[0]['server_key'] = 'secret-server-key'; $prepared[1] = 'vmess'; $prepared[2] = [$user->id => [10, 20]]; return $prepared; });
        $ack = $this->submit($node, 'native-receipt-hooks', [$user->id => [1, 2]]);
        $row = $this->row($ack['receipt_id']);
        $input = json_decode($row->settlement_input, true);
        $this->assertSame('vmess', $input['protocol']); $this->assertSame([$user->id => [10, 20]], $input['data']);
        $this->assertSame(['id', 'rate'], array_keys($input['server']));
        $this->assertStringNotContainsString('secret-', json_encode((array) $row));
        app(NativeTrafficReceipt::class)->recover((int) $row->id);
        $this->assertSame(15, (int) $user->fresh()->u); $this->assertSame(30, (int) $user->fresh()->d);
    }

    public function test_http_conflict_returns409_and_durability_failure_returns503_without_jobs(): void
    {
        admin_setting(['server_token' => 'native-receipt-global-fixture']);
        $node = $this->node(); $headers = ['Authorization' => 'Bearer native-receipt-global-fixture', 'X-TX-Node-ID' => (string) $node->id];
        $first = ['protocol_version' => 1, 'traffic_batch_id' => 'native-http-conflict', 'traffic' => [7 => [1, 2]]];
        $this->postJson('/txapi/node/v1/report', $first, $headers)->assertStatus(202)->assertJsonPath('data.receipt_status', 'persisted');
        $before = DB::table('tx_native_traffic_receipt')->first();
        $first['traffic'][7] = [9, 2];
        $this->postJson('/txapi/node/v1/report', $first, $headers)->assertStatus(409)->assertJsonPath('error.code', 'TRAFFIC_BATCH_CONFLICT');
        $this->assertEquals($before, DB::table('tx_native_traffic_receipt')->first());
        Bus::assertDispatchedTimes(TrafficBatchJob::class, 1);
        Schema::drop('tx_native_traffic_receipt');
        $first['traffic_batch_id'] = 'native-http-unavailable';
        $this->postJson('/txapi/node/v1/report', $first, $headers)->assertStatus(503)->assertJsonPath('error.code', 'TRAFFIC_DURABILITY_UNAVAILABLE');
        Bus::assertDispatchedTimes(TrafficBatchJob::class, 1);
    }

    public function test_lost_queue_wakeup_recovers_original_batch_after_rate_change(): void
    {
        $node = $this->node(); $user = $this->user();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('synthetic Redis unavailable'));
        $ack = $this->submit($node, 'native-queue-loss-001', [$user->id => [5, 9]]);
        $this->assertSame('persisted', $ack['receipt_status']); $this->assertSame('pending', $this->row($ack['receipt_id'])->status);
        $node->update(['rate' => 3]);
        $this->assertSame(0, Artisan::call('traffic:native-recover', ['--limit' => 1]));
        $this->assertSame(7, (int) $user->fresh()->u); $this->assertSame(13, (int) $user->fresh()->d);
        $this->assertSame('settled', $this->row($ack['receipt_id'])->status);
        $this->assertSame(1, DB::table('tx_traffic_batch')->count());
    }

    public function test_recovery_marks_already_committed_ledger_without_charging_again(): void
    {
        $node = $this->node(); $user = $this->user();
        $ack = $this->submit($node, 'native-ledger-crash-001', [$user->id => [5, 9]]);
        $row = $this->row($ack['receipt_id']); $input = json_decode($row->settlement_input, true);
        // Original job commits, but this old five-argument worker has no marker.
        (new TrafficBatchJob($input['server'], $input['data'], $input['protocol'], $input['record_at'], $row->batch_id))->handle();
        $this->assertSame('pending', $this->row($row->id)->status);
        $ledgerBefore = (array) DB::table('tx_traffic_batch')->first();
        app(NativeTrafficReceipt::class)->recover((int) $row->id);
        $this->assertSame($ledgerBefore, (array) DB::table('tx_traffic_batch')->first());
        $this->assertSame(7, (int) $user->fresh()->u); $this->assertSame(13, (int) $user->fresh()->d);
        $this->assertSame('settled', $this->row($row->id)->status);
    }

    public function test_crash_between_ledger_and_receipt_marker_is_recoverable(): void
    {
        $node = $this->node(); $user = $this->user(); $ack = $this->submit($node, 'native-marker-fault', [$user->id => [5, 9]]);
        $count = 0;
        Event::listen(TransactionCommitting::class, function () use (&$count): void { if (++$count === 2) throw new RuntimeException('synthetic marker crash'); });
        try { Bus::dispatched(TrafficBatchJob::class)->first()->handle(); $this->fail('marker fault not injected'); } catch (RuntimeException $error) { $this->assertSame('synthetic marker crash', $error->getMessage()); }
        Event::forget(TransactionCommitting::class);
        $this->assertSame(1, DB::table('tx_traffic_batch')->count()); $this->assertSame('pending', $this->row($ack['receipt_id'])->status);
        app(NativeTrafficReceipt::class)->recover((int) $ack['receipt_id']);
        $this->assertSame(7, (int) $user->fresh()->u); $this->assertSame('settled', $this->row($ack['receipt_id'])->status);
    }

    public function test_conflicting_ledger_cannot_be_fabricated_as_settled_and_is_visible_to_recovery(): void
    {
        $node = $this->node(); $ack = $this->submit($node, 'native-ledger-conflict', [7 => [1, 2]]);
        DB::table('tx_traffic_batch')->insert(['server_id' => $node->id, 'batch_id' => 'native-ledger-conflict', 'payload_hash' => str_repeat('a', 64), 'created_at' => time()]);
        $this->assertSame(1, Artisan::call('traffic:native-recover', ['--limit' => 1]));
        $row = $this->row($ack['receipt_id']);
        $this->assertSame('pending', $row->status); $this->assertSame(1, (int) $row->attempts); $this->assertSame('NATIVE_TRAFFIC_RECOVERY_FAILED', $row->last_error_code);
        $this->assertNull($row->quota_checked_at);
    }

    public function test_receipt_tamper_and_queued_input_tamper_fail_before_billing(): void
    {
        $node = $this->node(); $user = $this->user(); $ack = $this->submit($node, 'native-input-tamper', [$user->id => [1, 2]]);
        $row = $this->row($ack['receipt_id']); $input = json_decode($row->settlement_input, true);
        try { (new TrafficBatchJob(['id' => $node->id, 'rate' => 9], $input['data'], $input['protocol'], $input['record_at'], $row->batch_id, (int) $row->id))->handle(); $this->fail('tampered rate billed'); }
        catch (RuntimeException $error) { $this->assertSame('NATIVE_TRAFFIC_SETTLEMENT_INPUT_CONFLICT', $error->getMessage()); }
        $this->assertSame(0, DB::table('tx_traffic_batch')->count()); $this->assertSame(0, (int) $user->fresh()->u);
        DB::table('tx_native_traffic_receipt')->where('id', $row->id)->update(['settlement_input' => '{}']);
        $this->assertSame(1, Artisan::call('traffic:native-recover'));
        $this->assertSame(0, DB::table('tx_traffic_batch')->count());
    }

    public function test_unknown_outer_transaction_cannot_return_durable_ack(): void
    {
        $node = $this->node();
        DB::beginTransaction();
        try { $this->submit($node, 'native-outer-uncommitted', [7 => [1, 2]]); $this->fail('uncommitted receipt acknowledged'); }
        catch (NodeReportError $error) { $this->assertSame('TRAFFIC_DURABILITY_UNAVAILABLE', $error->errorCode); }
        finally { DB::rollBack(); }
        $this->assertSame(0, DB::table('tx_native_traffic_receipt')->count()); Bus::assertNotDispatched(TrafficBatchJob::class);
    }

    public function test_fence_failure_prevents_acceptance_and_dispatch(): void
    {
        $node = $this->node(); config(['authority_writer_fence.enabled' => true]);
        try { $this->submit($node, 'native-fence-blocked', [7 => [1, 2]]); $this->fail('fenced receipt accepted'); }
        catch (NodeReportError $error) { $this->assertSame('TRAFFIC_DURABILITY_UNAVAILABLE', $error->errorCode); }
        $this->assertSame(0, DB::table('tx_native_traffic_receipt')->count()); Bus::assertNotDispatched(TrafficBatchJob::class);
    }

    public function test_quota_marker_requires_real_settlement_and_preserves_acceptance_hash(): void
    {
        $node = $this->node(); $user = $this->user(); $ack = $this->submit($node, 'native-quota-marker', [$user->id => [1, 2]]); $row = $this->row($ack['receipt_id']);
        try { app(NativeTrafficReceipt::class)->markQuotaChecked((int) $row->id); $this->fail('pending quota marked checked'); }
        catch (RuntimeException $error) { $this->assertSame('NATIVE_TRAFFIC_SETTLEMENT_UNCONFIRMED', $error->getMessage()); }
        app(NativeTrafficReceipt::class)->recover((int) $row->id);
        app(NativeTrafficReceipt::class)->markQuotaChecked((int) $row->id); $checked = $this->row($row->id);
        app(NativeTrafficReceipt::class)->markQuotaChecked((int) $row->id);
        $this->assertNotNull($checked->quota_checked_at); $this->assertEquals($checked, $this->row($row->id)); $this->assertSame($row->content_hash, $checked->content_hash);
    }

    public function test_recovery_limit_is_bounded_and_invalid_limit_does_not_execute(): void
    {
        $node = $this->node();
        $this->submit($node, 'native-bounded-1', [7 => [1, 2]]); $this->submit($node, 'native-bounded-2', [7 => [3, 4]]);
        $this->assertSame(1, Artisan::call('traffic:native-recover', ['--limit' => 0])); $this->assertSame(0, DB::table('tx_traffic_batch')->count());
        $this->assertSame(0, Artisan::call('traffic:native-recover', ['--limit' => 1]));
        $this->assertSame(1, DB::table('tx_native_traffic_receipt')->where('status', 'pending')->count());
        $this->assertSame(1, DB::table('tx_native_traffic_receipt')->where('status', 'settled')->count());
    }

    public function test_legacy_switch_does_not_advertise_durability_or_disable_prior_recovery(): void
    {
        admin_setting(['server_token' => 'native-receipt-global-fixture']);
        $node = $this->node(); $user = $this->user(); $ack = $this->submit($node, 'native-switch-prior', [$user->id => [1, 2]]);
        config(['native_traffic_receipts.enabled' => false]);
        $legacy = $this->submit($node, 'native-switch-legacy', [$user->id => [3, 4]]);
        $this->assertSame('queued_only', $legacy['receipt_status']); $this->assertNull($legacy['receipt_id']);
        $this->assertSame(1, DB::table('tx_native_traffic_receipt')->count());
        $this->assertSame(0, Artisan::call('traffic:native-recover'));
        $this->assertSame('settled', $this->row($ack['receipt_id'])->status);
        $headers = ['Authorization' => 'Bearer native-receipt-global-fixture', 'X-TX-Node-ID' => (string) $node->id];
        $caps = $this->postJson('/txapi/node/v1/handshake', [], $headers)->assertOk()->json('data.capabilities');
        $this->assertNotContains('traffic_receipt_durable_v1', $caps);
        config(['native_traffic_receipts.enabled' => true]);
        $caps = $this->postJson('/txapi/node/v1/handshake', [], $headers)->assertOk()->json('data.capabilities');
        $this->assertContains('traffic_receipt_durable_v1', $caps);
    }
    public function test_accept_commit_fault_rolls_back_physical_transaction_and_returns_no_ack(): void
    {
        $node = $this->node(); $fail = true;
        Event::listen(TransactionCommitting::class, function () use (&$fail): void { if ($fail) throw new RuntimeException('synthetic acceptance commit failure'); });
        try { $this->submit($node, 'native-accept-commit-fault', [7 => [1, 2]]); $this->fail('uncommitted acceptance returned ACK'); }
        catch (NodeReportError $error) { $this->assertSame('TRAFFIC_DURABILITY_UNAVAILABLE', $error->errorCode); }
        $fail = false;
        $this->assertSame(0, DB::transactionLevel()); $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame(0, DB::table('tx_native_traffic_receipt')->count()); Bus::assertNotDispatched(TrafficBatchJob::class);
        $ack = $this->submit($node, 'native-accept-commit-fault', [7 => [1, 2]]);
        $this->assertSame('persisted', $ack['receipt_status']); $this->assertSame(1, DB::table('tx_native_traffic_receipt')->count());
    }

    public function test_post_hook_identity_change_and_empty_prepared_input_are_not_acknowledged(): void
    {
        $node = $this->node();
        HookManager::registerFilter('traffic.process.before', function ($prepared): array { $prepared[0]['id'] = 9999; return $prepared; });
        try { $this->submit($node, 'native-hook-wrong-node', [7 => [1, 2]]); $this->fail('hook reassigned authenticated node'); }
        catch (NodeReportError $error) { $this->assertSame('INVALID_TRAFFIC', $error->errorCode); }
        $this->app->instance('hook.filters', []);
        HookManager::registerFilter('traffic.process.before', function ($prepared): array { $prepared[2] = []; return $prepared; });
        try { $this->submit($node, 'native-hook-empty', [7 => [1, 2]]); $this->fail('empty settlement input acknowledged'); }
        catch (NodeReportError $error) { $this->assertSame('INVALID_TRAFFIC', $error->errorCode); }
        $this->assertSame(0, DB::table('tx_native_traffic_receipt')->count()); Bus::assertNotDispatched(TrafficBatchJob::class);
    }

    public function test_overflow_rolls_back_ledger_and_leaves_receipt_for_visible_recovery(): void
    {
        $node = $this->node(); $user = $this->user(); $user->update(['u' => PHP_INT_MAX - 1]);
        $ack = $this->submit($node, 'native-overflow-pending', [$user->id => [10, 1]]);
        $this->assertSame(1, Artisan::call('traffic:native-recover'));
        $this->assertSame(0, DB::table('tx_traffic_batch')->count());
        $this->assertSame('pending', $this->row($ack['receipt_id'])->status);
        $this->assertSame(PHP_INT_MAX - 1, (int) $user->fresh()->u);
        $this->assertSame(0, (int) $node->fresh()->u);
        $this->assertSame(0, DB::table('tx_stat_user')->count());
    }

}
