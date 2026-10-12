<?php

namespace Tests\Feature\UsageV2;

use App\Domains\UsageV2\UsageError;
use App\Domains\UsageV2\UsagePayload;
use App\Domains\UsageV2\UsagePolicy;
use App\Domains\UsageV2\UsageReceipts;
use App\Domains\UsageV2\UsageRegistry;
use App\Domains\UsageV2\UsageSettlement;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsageV2Fixture;
use Tests\TestCase;

/** Run separately, without RefreshDatabase's enclosing transaction. */
final class UsageMysqlConcurrencyTest extends TestCase
{
    use UsageV2Fixture;

    public function test_real_mysql_receipt_races_worker_races_and_process_crash_preserve_one_atomic_settlement(): void
    {
        if (DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires isolated MySQL and pcntl');
        }
        $this->assertSame('txboard_usage_test', DB::connection()->getDatabaseName(), 'Concurrency fixture must use its exclusive disposable database');
        $this->usageConfig(); $f = $this->usageFixture(); $p = $this->usagePayload($f, 'mysql_usage_'.bin2hex(random_bytes(8)));
        $receipts = $this->parallel(24, fn () => app(UsageReceipts::class)->submit($f['node']->id, $p, $f['now']));
        $this->assertCount(1, array_unique(array_column($receipts, 'receipt_id')));
        $this->assertSame(1, DB::table('tx_usage_batch')->where('source_id', $f['source']->id)->count());
        $batchId = (int) DB::table('tx_usage_batch')->where('source_id', $f['source']->id)->value('id');
        $this->assertSame(1, DB::table('tx_usage_outbox')->where('batch_id', $batchId)->count());
        $altered = $p['payload']; $altered['entries'][0]['raw_up_bytes'] = '4';
        $canonical = json_encode($altered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $conflict = (new UsagePayload())->submission(json_encode(['payload' => $altered,'payload_digest' => 'sha256:'.hash('sha256', $canonical)]));
        $conflicts = $this->parallel(12, function () use ($f, $conflict) {
            try { app(UsageReceipts::class)->submit($f['node']->id, $conflict, $f['now']); return ['code' => 'WRONG']; }
            catch (UsageError $e) { return ['code' => $e->errorCode]; }
        });
        $this->assertSame(['BATCH_PAYLOAD_CONFLICT'], array_values(array_unique(array_column($conflicts, 'code'))));

        // Terminate the actual process after projection mutation, before COMMIT.
        DB::disconnect('mysql');
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::purge('mysql');
            $worker = new UsageSettlement(app(UsagePolicy::class), function ($point) {
                if ($point === 'after_period') { exit(23); }
            });
            $fence = $worker->claim($batchId, $f['now']); $worker->settle($batchId, $fence, $f['now']); exit(24);
        }
        $this->assertGreaterThan(0, $pid); pcntl_waitpid($pid, $status); $this->assertSame(23, pcntl_wexitstatus($status));
        DB::purge('mysql');
        $this->assertSame('0', (string) DB::table('tx_usage_period')->where('id', $f['period'])->value('billable_bytes'));
        $this->assertSame(0, DB::table('tx_usage_entry')->where('batch_id', $batchId)->count());
        $workers = $this->parallel(16, fn () => ['states' => app(UsageSettlement::class)->scan(100, $f['now'] + 31)]);
        $this->assertCount(16, $workers); $this->assertSame('settled', DB::table('tx_usage_batch')->where('id', $batchId)->value('status'));
        $this->assertSame('11', (string) DB::table('tx_usage_period')->where('id', $f['period'])->value('billable_bytes'));
        $this->assertSame(1, DB::table('tx_usage_entry')->where('batch_id', $batchId)->count());
        $this->assertDatabaseHas('tx_node_usage_stat',['node_id' => $f['node']->id,'raw_up_bytes' => 3,'raw_down_bytes' => 5]);
        $this->assertSame(1, DB::table('tx_usage_outbox')->where('batch_id', $batchId)->where('kind','usage.settled')->count());
    }

    private function parallel(int $count, \Closure $action): array
    {
        $dir = sys_get_temp_dir().'/txboard-usage-race-'.bin2hex(random_bytes(6)); mkdir($dir, 0700); $children = [];
        DB::disconnect('mysql');
        try {
            for ($i = 0; $i < $count; $i++) {
                $pid = pcntl_fork();
                if ($pid < 0) { throw new \RuntimeException('Fork failed'); }
                if ($pid === 0) {
                    try {
                        DB::purge('mysql'); $deadline = microtime(true) + 20;
                        while (!is_file($dir.'/go') && microtime(true) < $deadline) { usleep(1000); }
                        if (!is_file($dir.'/go')) { throw new \RuntimeException('Barrier timeout'); }
                        file_put_contents($dir.'/'.$i, json_encode($action(), JSON_THROW_ON_ERROR)); exit(0);
                    } catch (\Throwable $e) { file_put_contents($dir.'/'.$i, json_encode(['error' => $e->getMessage()])); exit(2); }
                }
                $children[] = $pid;
            }
            touch($dir.'/go');
            foreach ($children as $pid) { pcntl_waitpid($pid, $status); $this->assertSame(0, pcntl_wexitstatus($status)); }
            $children = []; DB::purge('mysql'); $results = [];
            for ($i = 0; $i < $count; $i++) {
                $value = json_decode(file_get_contents($dir.'/'.$i), true, 32, JSON_THROW_ON_ERROR);
                $this->assertArrayNotHasKey('error', $value, json_encode($value)); $results[] = $value;
            }
            return $results;
        } finally {
            foreach ($children as $pid) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
            foreach (glob($dir.'/*') ?: [] as $path) { unlink($path); } rmdir($dir); DB::purge('mysql');
        }
    }

    public function test_registry_reuses_current_rows_inside_an_older_repeatable_read_snapshot(): void
    {
        if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped('Requires isolated MySQL MVCC sessions'); }
        $this->assertSame('txboard_usage_test', DB::connection()->getDatabaseName()); $this->usageConfig(); $f = $this->usageFixture();
        config(['database.connections.usage_mvcc_peer' => config('database.connections.mysql')]);
        $peer = DB::connection('usage_mvcc_peer');
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            // Establish an old read view before the independent SQL session commits changes.
            $this->assertSame('1', (string) DB::table('tx_node_usage_source')->where('node_id', $f['node']->id)->max('source_epoch'));
            $this->assertSame('active', DB::table('tx_subscription')->where('id', $f['sub'])->value('status'));
            $this->assertGreaterThan($f['now'], (int) DB::table('tx_usage_period')->where('id', $f['period'])->value('ends_at'));
            $peer->table('tx_node_usage_source')->where('id', $f['source']->id)->update(['current' => false]);
            $peer->table('tx_node_usage_source')->insert(['node_id' => $f['node']->id, 'source_epoch' => 2,
                'installation_ref' => 'installation_mvcc_peer_0002', 'core_instance_id' => config('core_usage_v2.core_instance_id'),
                'receipt_generation' => 1, 'current' => true, 'created_at' => $f['now']]);
            $peer->table('tx_subscription')->where('id', $f['sub'])->update(['status' => 'suspended']);
            $peer->table('tx_usage_period')->where('id', $f['period'])->update(['ends_at' => $f['now'] - 50]);
            $this->assertSame('1', (string) DB::table('tx_node_usage_source')->where('node_id', $f['node']->id)->max('source_epoch'));
            $this->assertSame('active', DB::table('tx_subscription')->where('id', $f['sub'])->value('status'));
            $this->assertGreaterThan($f['now'], (int) DB::table('tx_usage_period')->where('id', $f['period'])->value('ends_at'));
            $registry = app(UsageRegistry::class);
            $existing = $registry->source($f['node']->id, 'installation_mvcc_peer_0002', $f['now']);
            $this->assertSame('2', (string) $existing->source_epoch);
            $new = $registry->source($f['node']->id, 'installation_mvcc_fresh_0003', $f['now']);
            $this->assertSame('3', (string) $new->source_epoch);
            try {
                $registry->grant($new->id, $f['subject'], $f['terms'], $f['period'], $f['tariff']->id, $f['now'] - 100, $f['now'] + 100);
                $this->fail('Stale subscription or period was authorized');
            } catch (UsageError $e) { $this->assertSame('USAGE_GRANT_OWNERSHIP_OR_COVERAGE', $e->errorCode); }
        } finally { DB::rollBack(); DB::purge('usage_mvcc_peer'); }
    }
}
