<?php

namespace Tests\Feature\UsageV2;

use App\Domains\UsageV2\UsageError;
use App\Domains\UsageV2\UsagePayload;
use App\Domains\UsageV2\UsageReceipts;
use App\Domains\UsageV2\UsageRegistry;
use App\Domains\UsageV2\UsageSettlement;
use App\Services\Subscription\SubscriptionTermsShadowService;
use App\Services\Subscription\SubscriptionTermsTimeline;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** No enclosing test transaction: the isolated Terms writer requires its own fresh transaction. */
final class UsageTermsBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['core_shadow.isolated_copy' => true, 'core_usage_v2.isolated_copy' => true,
            'core_usage_v2.core_instance_id' => 'core_instance_terms_0001', 'core_usage_v2.receipt_generation' => '1']);
        $connection = 'usage_terms_testing';
        if (getenv('CORE_USAGE_TERMS_TEST_MYSQL') === '1') {
            config(['database.connections.usage_terms_testing' => config('database.connections.mysql')]);
            config(['database.default' => $connection]); DB::purge($connection);
            $this->assertSame('txboard_usage_test', DB::connection()->getDatabaseName(), 'Only the exclusive disposable usage database may be rebuilt');
            $this->assertTrue(app()->environment('testing'));
            Schema::dropAllTables();
        } else {
            config(['database.connections.usage_terms_testing' => array_merge(config('database.connections.sqlite'),
                ['database' => ':memory:', 'foreign_key_constraints' => true]), 'database.default' => $connection]);
            DB::purge($connection);
        }
        Schema::create('tx_user', function (Blueprint $t) {
            $t->integer('id', true); $t->boolean('banned')->default(false);
            $t->unsignedBigInteger('u')->default(0); $t->unsignedBigInteger('d')->default(0);
            $t->unsignedBigInteger('transfer_enable')->default(1000); $t->unsignedBigInteger('expired_at')->nullable();
            $t->unsignedBigInteger('created_at'); $t->unsignedBigInteger('updated_at');
        });
        Schema::create('tx_server', fn (Blueprint $t) => $t->bigIncrements('id'));
        foreach (['000001_create_subscription_shadow_tables', '000003_create_node_usage_'.'v2'.'_tables',
            '000005_create_subscription_terms_shadow_operations'] as $name) {
            (require database_path('migrations/2026_10_12_'.$name.'.php'))->up();
        }
    }

    protected function tearDown(): void
    {
        DB::purge('usage_terms_testing'); parent::tearDown();
    }

    public function test_new_terms_grant_uses_existing_period_without_granting_another_allowance(): void
    {
        $f = $this->fixture(); $old = DB::table('tx_subscription_terms')->where('id', $f['terms'])->first();
        $change = $this->change($f);
        $new = $this->grant($f, $change['terms_id'], $f['period'], $f['at'], $f['at'] + 60);
        $this->assertSame($f['period'], (string) $new->usage_period_id);
        $this->assertSame($f['terms'], (string) DB::table('tx_usage_period')->value('terms_id'));
        $this->assertSame('333', (string) DB::table('tx_usage_period')->value('billable_bytes'));
        $this->assertSame(1, DB::table('tx_usage_period')->count());
        $this->assertEquals($old, DB::table('tx_subscription_terms')->where('id', $f['terms'])->first());
        $this->reject(fn () => $this->grant($f, $f['terms'], $f['period'], $f['at'], $f['at'] + 1), 'USAGE_TERMS_BOUNDARY');
        $this->reject(fn () => $this->grant($f, $change['terms_id'], $f['period'], $f['at'] - 1, $f['at'] + 1), 'USAGE_TERMS_BOUNDARY');
    }

    public function test_transition_cutoff_rejects_old_grant_crossing_before_persisted_ack(): void
    {
        $f = $this->fixture(); $this->change($f);
        $this->reject(fn () => $this->submit($f, $f['at'] - 10, $f['at'] + 1), 'USAGE_TERMS_BOUNDARY');
        $this->reject(fn () => $this->submit($f, $f['at'], $f['at'] + 1), 'USAGE_TERMS_BOUNDARY');
        $this->assertSame(0, DB::table('tx_usage_batch')->count());
        $this->assertSame(0, DB::table('tx_usage_outbox')->count());
    }

    public function test_boundary_exact_end_and_exact_successor_start_settle_to_same_original_period(): void
    {
        $f = $this->fixture(); $legacy = DB::table('tx_user')->first();
        $oldReceipt = $this->submit($f, $f['at'] - 20, $f['at']);
        $change = $this->change($f); $next = $this->next($f, $change['terms_id'], $f['period']);
        $newReceipt = $this->submit($next, $f['at'], $f['at'] + 10, 'usage_terms_new_0001');
        $this->assertSame($oldReceipt, $this->submit($f, $f['at'] - 20, $f['at']));
        $this->assertSame(['settled', 'settled'], array_values(app(UsageSettlement::class)->scan(10, $f['at'] + 11)));
        $this->assertSame('355', (string) DB::table('tx_usage_period')->value('billable_bytes'));
        $this->assertSame([$f['terms'], $change['terms_id']], DB::table('tx_usage_entry')->orderBy('id')->pluck('terms_id')->map(fn ($id) => (string) $id)->all());
        $this->assertSame(1, DB::table('tx_usage_period')->count());
        $this->assertEquals($legacy, DB::table('tx_user')->first());
        $this->assertNotSame($oldReceipt['receipt_id'], $newReceipt['receipt_id']);
    }

    public function test_capability_stops_advertising_superseded_terms_grants_at_boundary(): void
    {
        $f = $this->fixture(); $registry = app(UsageRegistry::class);
        $this->assertSame((string) $f['grant']->id, $registry->capability($f['node'], $f['at'] - 1)['grants'][0]['auth_snapshot_id']);
        $change = $this->change($f);
        $this->assertSame([], $registry->capability($f['node'], $f['at'])['grants']);
        $next = $this->next($f, $change['terms_id'], $f['period']);
        $cap = $registry->capability($f['node'], $f['at']);
        $this->assertCount(1, $cap['grants']);
        $this->assertSame((string) $next['grant']->id, $cap['grants'][0]['auth_snapshot_id']);
        $this->assertSame($f['period'], $cap['grants'][0]['usage_period_id']);
    }

    public function test_capability_exposes_only_the_known_remaining_lease_before_future_boundary(): void
    {
        $f = $this->fixture(); $boundary = $f['at'] + 20; $this->change(array_replace($f, ['at' => $boundary]));
        $cap = app(UsageRegistry::class)->capability($f['node'], $f['at']);
        $this->assertSame($boundary, $cap['grants'][0]['lease_expires_at']);
        $this->assertSame($f['at'] + 100, (int) DB::table('tx_auth_snapshot')->where('id', $f['grant']->id)->value('lease_expires_at'));
        $this->assertSame([], app(UsageRegistry::class)->capability($f['node'], $boundary)['grants']);
    }

    public function test_gap_in_projected_terms_does_not_use_a_neighbouring_grant(): void
    {
        $f = $this->fixture(); $this->change($f);
        DB::table('tx_subscription_terms')->where('id', $f['terms'])->update(['effective_until' => $f['at'] - 10]);
        $this->assertNull(app(SubscriptionTermsTimeline::class)->at($f['sub'], $f['at'] - 5));
        $this->reject(fn () => $this->submit($f, $f['at'] - 9, $f['at'] - 1), 'USAGE_TERMS_BOUNDARY');
        $this->assertSame(0, DB::table('tx_usage_batch')->count());
        $this->submit($f, $f['at'] - 20, $f['at'] - 10);
        $this->assertSame(['settled'], array_values(app(UsageSettlement::class)->scan(10, $f['at'])));
    }

    public function test_overlapping_or_unknown_period_evidence_blocks_fresh_receipts(): void
    {
        $f = $this->fixture();
        $other = DB::table('tx_usage_period')->insertGetId(['subscription_id' => $f['sub'], 'terms_id' => $f['terms'],
            'period_no' => 2, 'starts_at' => $f['at'] - 5, 'ends_at' => $f['at'] + 5, 'boundary_kind' => 'known',
            'created_at' => $f['at'], 'updated_at' => $f['at']]);
        $this->reject(fn () => $this->submit($f, $f['at'] - 10, $f['at']), 'USAGE_PERIOD_BOUNDARY_AMBIGUOUS');
        DB::table('tx_usage_period')->where('id', $other)->update(['boundary_kind' => 'legacy_boundary_unknown', 'starts_at' => null]);
        $this->reject(fn () => $this->submit($f, $f['at'] - 20, $f['at'] - 10), 'USAGE_PERIOD_BOUNDARY_UNKNOWN');
        $this->assertSame(0, DB::table('tx_usage_batch')->count()); $this->assertSame(0, DB::table('tx_usage_outbox')->count());
    }

    public function test_changed_immutable_terms_evidence_quarantines_without_partial_charge(): void
    {
        $f = $this->fixture(); $this->submit($f, $f['at'] - 20, $f['at']);
        DB::table('tx_subscription_terms')->where('id', $f['terms'])->update(['content_hash' => str_repeat('b', 64)]);
        $this->assertSame(['quarantined'], array_values(app(UsageSettlement::class)->scan(10, $f['at'])));
        $this->assertSame('USAGE_FROZEN_TERMS_CHANGED', DB::table('tx_usage_batch')->value('reason_code'));
        $this->assertSame('333', (string) DB::table('tx_usage_period')->value('billable_bytes'));
        $this->assertSame(0, DB::table('tx_usage_entry')->count());
    }

    public function test_accepted_crossing_interval_blocks_terms_change_and_reset_in_every_receipt_state(): void
    {
        $f = $this->fixture(); $this->submit($f, $f['at'] - 10, $f['at'] + 10);
        foreach (['pending', 'processing', 'retry_wait', 'quarantined', 'settled'] as $state) {
            DB::table('tx_usage_batch')->update(['status' => $state]);
            $this->reject(fn () => $this->change($f), 'TERMS_ACCEPTED_USAGE_WOULD_BE_REASSIGNED');
            $this->reject(fn () => $this->change($f, true), 'TERMS_ACCEPTED_USAGE_WOULD_BE_REASSIGNED');
            $this->assertSame(0, DB::table('tx_subscription_terms_transition')->count());
            $this->assertSame(0, DB::table('tx_subscription_terms_shadow_operation')->count());
            $this->assertSame(1, DB::table('tx_usage_period')->count());
        }
    }

    public function test_reset_keeps_pending_historical_usage_in_closed_period_and_starts_zero_counters(): void
    {
        $f = $this->fixture(); $this->submit($f, $f['at'] - 20, $f['at']);
        $reset = $this->change($f, true); $newPeriod = $reset['usage_period_id'];
        $this->assertSame('0', (string) DB::table('tx_usage_period')->where('id', $newPeriod)->value('billable_bytes'));
        $this->assertSame('333', (string) DB::table('tx_usage_period')->where('id', $f['period'])->value('billable_bytes'));
        $next = $this->next($f, $f['terms'], $newPeriod);
        $this->submit($next, $f['at'], $f['at'] + 10, 'usage_terms_reset_0001');
        $this->assertSame(['settled', 'settled'], array_values(app(UsageSettlement::class)->scan(10, $f['at'] + 11)));
        $this->assertSame('344', (string) DB::table('tx_usage_period')->where('id', $f['period'])->value('billable_bytes'));
        $this->assertSame('11', (string) DB::table('tx_usage_period')->where('id', $newPeriod)->value('billable_bytes'));
        $this->assertSame([$f['period'], $newPeriod], DB::table('tx_usage_entry')->orderBy('id')->pluck('usage_period_id')->map(fn ($id) => (string) $id)->all());
        $this->assertSame([], app(UsageSettlement::class)->scan(10, $f['at'] + 100));
    }

    public function test_late_report_after_reset_still_uses_its_original_period_and_crossing_is_rejected(): void
    {
        $f = $this->fixture(); $this->change($f, true);
        $this->reject(fn () => $this->submit($f, $f['at'] - 1, $f['at'] + 1, 'usage_terms_cross_0001'), 'USAGE_GRANT_OWNERSHIP_OR_COVERAGE');
        $this->submit($f, $f['at'] - 20, $f['at']);
        $this->assertSame(['settled'], array_values(app(UsageSettlement::class)->scan(10, $f['at'] + 1)));
        $this->assertSame('344', (string) DB::table('tx_usage_period')->where('id', $f['period'])->value('billable_bytes'));
    }

    public function test_retained_first_slice_evidence_settles_after_legitimate_terms_transition(): void
    {
        $f = $this->fixture(); $this->submit($f, $f['at'] - 20, $f['at']);
        $entries = json_decode(DB::table('tx_usage_batch')->value('frozen_entries'), true);
        foreach ($entries as &$entry) {
            foreach (['boundary_revision', 'period_creation_terms_id', 'terms_effective_from', 'terms_effective_until', 'terms_ending_transition_id'] as $key) { unset($entry[$key]); }
        } unset($entry);
        DB::table('tx_usage_batch')->update(['frozen_entries' => json_encode($entries), 'frozen_hash' => UsageRegistry::hash($entries)]);
        $this->change($f);
        $this->assertSame(['settled'], array_values(app(UsageSettlement::class)->scan(10, $f['at'] + 1)));
        $this->assertSame('344', (string) DB::table('tx_usage_period')->value('billable_bytes'));
    }

    public function test_live_period_boundary_damage_quarantines_without_moving_or_losing_receipt(): void
    {
        $f = $this->fixture(); $receipt = $this->submit($f, $f['at'] - 20, $f['at']);
        DB::table('tx_usage_period')->where('id', $f['period'])->update(['ends_at' => $f['at'] - 1]);
        $this->assertSame(['quarantined'], array_values(app(UsageSettlement::class)->scan(10, $f['at'])));
        $this->assertSame('USAGE_PERIOD_BOUNDARY', DB::table('tx_usage_batch')->value('reason_code'));
        $this->assertSame($receipt['receipt_id'], $this->submit($f, $f['at'] - 20, $f['at'])['receipt_id']);
        $this->assertSame('333', (string) DB::table('tx_usage_period')->value('billable_bytes'));
        $this->assertSame(0, DB::table('tx_usage_entry')->count());
        DB::table('tx_usage_period')->where('id', $f['period'])->update(['ends_at' => $f['at'] + 800]);
        $batch = (int) DB::table('tx_usage_batch')->value('id');
        app(UsageSettlement::class)->recover($batch, 'ISOLATED_BOUNDARY_REPAIR_VERIFIED', $f['at'] + 1);
        $this->assertSame(['settled'], array_values(app(UsageSettlement::class)->scan(10, $f['at'] + 2)));
        $this->assertSame('344', (string) DB::table('tx_usage_period')->value('billable_bytes'));
    }

    public function test_invalid_timeline_blocks_fresh_ack_but_preserves_existing_receipt_replay(): void
    {
        $f = $this->fixture(); $receipt = $this->submit($f, $f['at'] - 30, $f['at'] - 20);
        DB::table('tx_subscription')->where('id', $f['sub'])->update(['current_terms_id' => null]);
        $this->assertSame($receipt, $this->submit($f, $f['at'] - 30, $f['at'] - 20));
        $this->reject(fn () => $this->submit($f, $f['at'] - 10, $f['at'], 'usage_terms_damaged_0001'), 'USAGE_TERMS_TIMELINE_INVALID');
        $this->assertSame(1, DB::table('tx_usage_batch')->count());
        $this->assertSame(['quarantined'], array_values(app(UsageSettlement::class)->scan(10, $f['at'])));
        $this->assertSame('USAGE_TERMS_TIMELINE_INVALID', DB::table('tx_usage_batch')->value('reason_code'));
        $this->assertSame('333', (string) DB::table('tx_usage_period')->value('billable_bytes'));
    }

    public function test_real_mysql_receipt_and_boundary_writers_serialize_without_reassigning_accepted_usage(): void
    {
        $this->requireMysql();
        foreach ([false, true] as $reset) {
            for ($round = 0; $round < 3; $round++) {
                $f = $this->fixture();
                $results = $this->parallel([
                    fn () => $this->submit($f, $f['at'] - 10, $f['at'] + 10),
                    fn () => $this->change($f, $reset),
                ]);
                $this->assertSame(1, count(array_filter($results, fn ($r) => $r['ok'])));
                if ($results[0]['ok']) {
                    $this->assertSame('TERMS_ACCEPTED_USAGE_WOULD_BE_REASSIGNED', $results[1]['code']);
                    $this->assertSame(0, DB::table('tx_subscription_terms_shadow_operation')->where('subscription_id', $f['sub'])->count());
                } else {
                    $this->assertSame($reset ? 'USAGE_GRANT_OWNERSHIP_OR_COVERAGE' : 'USAGE_TERMS_BOUNDARY', $results[0]['code']);
                    $this->assertSame(0, DB::table('tx_usage_batch')->where('source_id', $f['source']->id)->count());
                }
                $this->assertSame('333', (string) DB::table('tx_usage_period')->where('id', $f['period'])->value('billable_bytes'));
            }
        }
    }

    public function test_real_mysql_pending_settlement_and_reset_preserve_closed_period_totals(): void
    {
        $this->requireMysql(); $f = $this->fixture(); $this->submit($f, $f['at'] - 20, $f['at']);
        $batchId = (int) DB::table('tx_usage_batch')->value('id');
        $results = $this->parallel([
            function () use ($f, $batchId) {
                $worker = app(UsageSettlement::class); $fence = $worker->claim($batchId, $f['at'] + 1);
                return ['status' => $worker->settle($batchId, $fence, $f['at'] + 1)];
            },
            fn () => $this->change($f, true),
        ]);
        $this->assertTrue($results[0]['ok']); $this->assertSame('settled', $results[0]['data']['status']);
        if (!$results[1]['ok']) {
            $this->assertSame('TERMS_PERIOD_VERSION_CONFLICT', $results[1]['code']);
            $reset = $this->change($f, true, '2');
        } else { $reset = $results[1]['data']; }
        $this->assertSame('344', (string) DB::table('tx_usage_period')->where('id', $f['period'])->value('billable_bytes'));
        $this->assertSame('closed', DB::table('tx_usage_period')->where('id', $f['period'])->value('status'));
        $this->assertSame('0', (string) DB::table('tx_usage_period')->where('id', $reset['usage_period_id'])->value('billable_bytes'));
        $this->assertSame(1, DB::table('tx_usage_entry')->where('batch_id', $batchId)->count());
        $this->assertSame('settled', DB::table('tx_usage_batch')->where('id', $batchId)->value('status'));
    }

    public function test_real_mysql_old_repeatable_read_view_cannot_authorize_superseded_terms(): void
    {
        $this->requireMysql(); $f = $this->fixture();
        config(['database.connections.usage_terms_peer' => config('database.connections.usage_terms_testing')]);
        DB::purge('usage_terms_peer');
        $this->assertNotSame(DB::selectOne('SELECT CONNECTION_ID() AS id')->id, DB::connection('usage_terms_peer')->selectOne('SELECT CONNECTION_ID() AS id')->id);
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); DB::beginTransaction();
        try {
            $timeline = app(SubscriptionTermsTimeline::class);
            $this->assertSame($f['terms'], $timeline->at($f['sub'], $f['at'])['terms_id']);
            config(['database.default' => 'usage_terms_peer']);
            try { $change = $this->change($f); } finally { config(['database.default' => 'usage_terms_testing']); }
            $this->assertSame($f['terms'], $timeline->at($f['sub'], $f['at'])['terms_id'], 'The old MVCC view must actually remain observable');
            $this->reject(fn () => $this->grant($f, $f['terms'], $f['period'], $f['at'], $f['at'] + 10), 'USAGE_TERMS_BOUNDARY');
            $next = $this->next($f, $change['terms_id'], $f['period']);
            $this->assertSame($change['terms_id'], (string) $next['grant']->terms_id);
            $this->reject(fn () => $this->submit($f, $f['at'] - 10, $f['at'] + 1), 'USAGE_TERMS_BOUNDARY');
            $this->submit($f, $f['at'] - 20, $f['at']);
            $this->submit($next, $f['at'], $f['at'] + 10, 'usage_terms_mvcc_0001');
            $this->assertSame(['settled', 'settled'], array_values(app(UsageSettlement::class)->scan(10, $f['at'] + 11)));
            $this->assertSame('355', (string) DB::table('tx_usage_period')->where('id', $f['period'])->value('billable_bytes'));
            $this->assertSame((string) $next['grant']->id, app(UsageRegistry::class)->capability($f['node'], $f['at'])['grants'][0]['auth_snapshot_id']);
        } finally {
            config(['database.default' => 'usage_terms_testing']); DB::rollBack(); DB::purge('usage_terms_peer');
        }
    }

    private function requireMysql(): void
    {
        if (getenv('CORE_USAGE_TERMS_TEST_MYSQL') !== '1' || DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires the exclusive usage MariaDB fixture and independent sessions');
        }
    }

    private function parallel(array $actions): array
    {
        $directory = sys_get_temp_dir().'/txboard-usage-terms-'.bin2hex(random_bytes(8)); mkdir($directory, 0700);
        $children = []; DB::disconnect('usage_terms_testing');
        try {
            foreach ($actions as $index => $action) {
                $pid = pcntl_fork();
                if ($pid < 0) { throw new \RuntimeException('Fork failed'); }
                if ($pid === 0) {
                    try {
                        DB::purge('usage_terms_testing'); $deadline = microtime(true) + 20;
                        while (!is_file($directory.'/go') && microtime(true) < $deadline) { usleep(1000); }
                        if (!is_file($directory.'/go')) { throw new \RuntimeException('Barrier timeout'); }
                        try { $result = ['ok' => true, 'data' => $action()]; }
                        catch (UsageError $error) { $result = ['ok' => false, 'code' => $error->errorCode]; }
                        catch (DomainException $error) { $result = ['ok' => false, 'code' => $error->getMessage()]; }
                        file_put_contents($directory.'/'.$index, json_encode($result, JSON_THROW_ON_ERROR)); exit(0);
                    } catch (\Throwable $error) {
                        file_put_contents($directory.'/'.$index, json_encode(['fatal' => get_class($error), 'code' => $error->getMessage()])); exit(1);
                    }
                }
                $children[] = $pid;
            }
            touch($directory.'/go');
            foreach ($children as $pid) { pcntl_waitpid($pid, $status); $this->assertSame(0, pcntl_wexitstatus($status)); }
            $children = []; DB::purge('usage_terms_testing'); $results = [];
            foreach (array_keys($actions) as $index) { $results[] = json_decode(file_get_contents($directory.'/'.$index), true, flags: JSON_THROW_ON_ERROR); }
            return $results;
        } finally {
            foreach ($children as $pid) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
            foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); } rmdir($directory);
            DB::purge('usage_terms_testing');
        }
    }

    private function fixture(): array
    {
        $at = time(); $node = (int) DB::table('tx_server')->insertGetId([]);
        $user = (string) DB::table('tx_user')->insertGetId(['u' => 111, 'd' => 222, 'created_at' => $at, 'updated_at' => $at]);
        $sub = (string) DB::table('tx_subscription')->insertGetId(['user_id' => $user, 'status' => 'active',
            'starts_at' => $at - 1000, 'expires_at' => $at + 1000, 'created_at' => $at, 'updated_at' => $at]);
        $terms = (string) DB::table('tx_subscription_terms')->insertGetId(['subscription_id' => $sub, 'version_no' => 1,
            'effective_from' => $at - 1000, 'effective_until' => null, 'quota_bytes' => 1000, 'billing_timezone' => 'UTC',
            'reset_policy' => '{"method":0}', 'content_hash' => str_repeat('a', 64), 'created_at' => $at]);
        DB::table('tx_subscription')->where('id', $sub)->update(['current_terms_id' => $terms]);
        DB::table('tx_subscription_slot')->insert(['user_id' => $user, 'slot_no' => 1, 'subscription_id' => $sub,
            'reserved_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
        $period = (string) DB::table('tx_usage_period')->insertGetId(['subscription_id' => $sub, 'terms_id' => $terms, 'period_no' => 1,
            'starts_at' => $at - 1000, 'ends_at' => $at + 800, 'quota_bytes' => 1000, 'upload_bytes' => 111, 'download_bytes' => 222,
            'billable_bytes' => 333, 'boundary_kind' => 'known', 'status' => 'open', 'last_reset_at' => $at - 1000,
            'next_reset_at' => $at + 800, 'created_at' => $at, 'updated_at' => $at]);
        $subject = (int) DB::table('tx_access_subject')->insertGetId(['subscription_id' => $sub, 'provider_id' => 'isolated-terms',
            'provider_subject_ref' => 'terms_subject_'.$sub, 'status' => 'active', 'created_at' => $at, 'updated_at' => $at]);
        $registry = app(UsageRegistry::class); $source = $registry->source($node, 'terms_installation_0001', $at);
        $tariff = $registry->tariff($node, '3', '2', $at - 1000, $at + 1000);
        $grant = $registry->grant($source->id, $subject, (int) $terms, (int) $period, $tariff->id, $at - 100, $at + 100);
        return compact('at', 'node', 'user', 'sub', 'terms', 'period', 'subject', 'source', 'tariff', 'grant');
    }

    private function grant(array $f, string $terms, string $period, int $from, int $until): object
    {
        return app(UsageRegistry::class)->grant($f['source']->id, $f['subject'], (int) $terms, (int) $period, $f['tariff']->id, $from, $until);
    }

    private function next(array $f, string $terms, string $period): array
    {
        return array_replace($f, ['terms' => $terms, 'period' => $period,
            'grant' => $this->grant($f, $terms, $period, $f['at'], $f['at'] + 60)]);
    }

    private function change(array $f, bool $reset = false, string $periodVersion = '1'): array
    {
        $kind = $reset ? 'reset' : 'quota';
        $request = ['kind' => $kind, 'policy_version' => $reset ? 'legacy-reset-explicit/v1' : 'shadow-quota-preserve/v1',
            'effective_at' => $f['at'], 'expected_subscription_version' => '1', 'expected_period_version' => $periodVersion,
            'changes' => $reset ? ['trigger' => 'manual', 'verified_legacy_rule' => 'legacy-performReset/manual/v1'] : ['quota_bytes' => '2000']];
        return app(SubscriptionTermsShadowService::class)->{$reset ? 'reset' : 'advance'}($f['user'], $f['sub'], 'terms_boundary_'.$kind, $request, $f['at']);
    }

    private function payload(array $f, int $start, int $end, string $batch): array
    {
        $payload = ['protocol_version' => 2, 'node_id' => (string) $f['node'], 'source_epoch' => (string) $f['source']->source_epoch,
            'batch_id' => $batch, 'started_at' => $start, 'ended_at' => $end,
            'entries' => [['access_subject_id' => (string) $f['subject'], 'auth_snapshot_id' => (string) $f['grant']->id,
                'tariff_revision_id' => (string) $f['tariff']->id, 'terms_id' => $f['terms'], 'usage_period_id' => $f['period'],
                'raw_up_bytes' => '3', 'raw_down_bytes' => '5']]];
        ksort($payload['entries'][0], SORT_STRING); ksort($payload, SORT_STRING);
        $canonical = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return (new UsagePayload())->submission(json_encode(['payload' => $payload, 'payload_digest' => 'sha256:'.hash('sha256', $canonical)]));
    }

    private function submit(array $f, int $start, int $end, string $batch = 'usage_terms_old_0001'): array
    {
        return app(UsageReceipts::class)->submit($f['node'], $this->payload($f, $start, $end, $batch), $f['at']);
    }

    private function reject(callable $call, string $code): void
    {
        try { $call(); $this->fail('Unsafe boundary accepted'); }
        catch (UsageError $error) { $this->assertSame($code, $error->errorCode); }
        catch (DomainException $error) { $this->assertSame($code, $error->getMessage()); }
    }
}
