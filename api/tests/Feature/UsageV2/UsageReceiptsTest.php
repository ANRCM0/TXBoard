<?php

namespace Tests\Feature\UsageV2;

use App\Domains\UsageV2\UsageError;
use App\Domains\UsageV2\UsagePolicy;
use App\Domains\UsageV2\UsageReceipts;
use App\Domains\UsageV2\UsageRegistry;
use App\Domains\UsageV2\UsageSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsageV2Fixture;
use Tests\TestCase;

final class UsageReceiptsTest extends TestCase
{
    use RefreshDatabase, UsageV2Fixture;

    protected function setUp(): void { parent::setUp(); $this->usageConfig(); }

    public function test_ack_requires_committed_payload_frozen_evidence_and_outbox_without_redis(): void
    {
        $f = $this->usageFixture(); config(['database.redis.default.port' => 1, 'queue.default' => 'redis']);
        $parsed = $this->usagePayload($f); $receipt = app(UsageReceipts::class)->submit($f['node']->id, $parsed, $f['now']);
        $this->assertSame('persisted', $receipt['receipt_status']); $this->assertSame('pending', $receipt['settlement_status']);
        $batch = DB::table('tx_usage_batch')->first(); $this->assertSame($parsed['canonical'], $batch->canonical_payload);
        $this->assertSame(UsageRegistry::hash(json_decode($batch->frozen_entries, true)), $batch->frozen_hash);
        $this->assertDatabaseHas('tx_usage_outbox', ['batch_id' => $batch->id, 'kind' => 'usage.accepted','status' => 'pending']);
        $this->assertSame(0, DB::table('tx_usage_entry')->count());
    }

    public function test_failure_before_commit_produces_neither_receipt_nor_orphaned_outbox(): void
    {
        $f = $this->usageFixture();
        foreach (['after_payload','after_outbox'] as $phase) {
            $receipts = new UsageReceipts(app(UsagePolicy::class), app(UsageRegistry::class), function ($point) use ($phase) {
                if ($point === $phase) { throw new \RuntimeException('injected'); }
            });
            try { $receipts->submit($f['node']->id, $this->usagePayload($f), $f['now']); $this->fail('Failure not injected'); }
            catch (\RuntimeException $e) { $this->assertSame('injected', $e->getMessage()); }
            $this->assertSame(0, DB::table('tx_usage_batch')->count()); $this->assertSame(0, DB::table('tx_usage_outbox')->count());
        }
    }

    public function test_lost_ack_replay_returns_original_receipt_and_conflicting_digest_is_rejected(): void
    {
        $f = $this->usageFixture(); $p = $this->usagePayload($f); $service = app(UsageReceipts::class);
        $original = $service->submit($f['node']->id, $p, $f['now']);
        $this->assertSame($original, $service->submit($f['node']->id, $p, $f['now'] + 999999));
        $query = array_intersect_key($p['payload'], array_flip(['protocol_version','node_id','source_epoch','batch_id'])) + ['payload_digest' => $p['digest']];
        $this->assertSame($original, $service->query($f['node']->id, $query));
        $p['digest'] = 'sha256:'.str_repeat('0',64);
        try { $service->submit($f['node']->id, $p, $f['now']); $this->fail('Conflict accepted'); }
        catch (UsageError $e) { $this->assertSame('BATCH_PAYLOAD_CONFLICT', $e->errorCode); }
        $this->assertDatabaseHas('tx_usage_outbox',['kind' => 'usage.conflict','status' => 'completed']);
    }

    public function test_batch_identity_changes_cannot_rebill_an_overlapping_interval(): void
    {
        $f = $this->usageFixture(); app(UsageReceipts::class)->submit($f['node']->id, $this->usagePayload($f), $f['now']);
        $this->expectExceptionMessage('USAGE_INTERVAL_OVERLAP');
        app(UsageReceipts::class)->submit($f['node']->id, $this->usagePayload($f, 'usage_batch_test_0002', 1), $f['now']);
    }

    public function test_revoked_historical_traffic_is_settled_but_crossing_revocation_is_rejected(): void
    {
        $f = $this->usageFixture(); DB::table('tx_access_subject')->where('id', $f['subject'])->update([
            'status' => 'revoked','revoked_at' => $f['now'] - 20, 'auth_version' => 2, 'revocation_fact_version' => 1]);
        app(UsageReceipts::class)->submit($f['node']->id, $this->usagePayload($f), $f['now']);
        $this->assertSame([$this->usageBatch($f) => 'settled'], app(UsageSettlement::class)->scan(10, $f['now']));
        $this->expectExceptionMessage('USAGE_GRANT_OWNERSHIP_OR_COVERAGE');
        app(UsageReceipts::class)->submit($f['node']->id, $this->usagePayload($f, 'usage_batch_test_0002', 30), $f['now']);
    }

    public function test_settlement_is_atomic_uses_frozen_tariff_and_is_idempotent(): void
    {
        $f = $this->usageFixture(); app(UsageReceipts::class)->submit($f['node']->id, $this->usagePayload($f), $f['now']);
        DB::table('tx_tariff_revision')->where('id', $f['tariff']->id)->update(['numerator' => 99]);
        $worker = app(UsageSettlement::class); $this->assertSame([$this->usageBatch($f) => 'settled'], $worker->scan(10, $f['now']));
        $this->assertSame([], $worker->scan(10, $f['now'] + 100));
        $period = DB::table('tx_usage_period')->first(); $this->assertSame('4', (string) $period->upload_bytes);
        $this->assertSame('7', (string) $period->download_bytes); $this->assertSame('11', (string) $period->billable_bytes);
        $this->assertDatabaseHas('tx_node_usage_stat',['node_id' => $f['node']->id,'raw_up_bytes' => 3,'raw_down_bytes' => 5]);
        $this->assertSame(1, DB::table('tx_usage_entry')->count());
        $this->assertDatabaseHas('tx_usage_outbox',['kind' => 'usage.settled','status' => 'pending']);
    }

    public function test_settlement_failure_rolls_back_all_projections_then_sql_scan_recovers(): void
    {
        $f = $this->usageFixture(); app(UsageReceipts::class)->submit($f['node']->id, $this->usagePayload($f), $f['now']);
        foreach (['after_period','after_ledger','before_commit'] as $phase) {
            $worker = new UsageSettlement(app(UsagePolicy::class), function ($point) use ($phase) {
                if ($point === $phase) { throw new \RuntimeException('injected'); }
            });
            $this->assertSame([$this->usageBatch($f) => 'retry_wait'], $worker->scan(10, $f['now']));
            $this->assertSame('0', (string) DB::table('tx_usage_period')->value('billable_bytes'));
            $this->assertSame(0, DB::table('tx_usage_entry')->count()); $this->assertSame(0, DB::table('tx_node_usage_stat')->count());
            $f['now'] += 300;
        }
        $this->assertSame([$this->usageBatch($f) => 'settled'], app(UsageSettlement::class)->scan(10, $f['now']));
        $this->assertSame('11', (string) DB::table('tx_usage_period')->value('billable_bytes'));
    }

    public function test_expired_worker_lease_is_reclaimed_and_old_fence_cannot_settle(): void
    {
        $f = $this->usageFixture(); app(UsageReceipts::class)->submit($f['node']->id, $this->usagePayload($f), $f['now']);
        $worker = app(UsageSettlement::class); $first = $worker->claim($this->usageBatch($f), $f['now']);
        $this->assertNull($worker->claim($this->usageBatch($f), $f['now'] + 1)); $second = $worker->claim($this->usageBatch($f), $f['now'] + 31);
        $this->assertNotSame($first, $second); $this->assertSame('stale', $worker->settle($this->usageBatch($f), $first, $f['now'] + 31));
        $this->assertSame('settled', $worker->settle($this->usageBatch($f), $second, $f['now'] + 31));
    }

    public function test_overflow_quarantine_is_retained_and_explicit_recovery_is_audited(): void
    {
        $f = $this->usageFixture(); $original = app(UsageReceipts::class)->submit($f['node']->id, $this->usagePayload($f), $f['now']);
        DB::table('tx_usage_period')->where('id', $f['period'])->update(['billable_bytes' => PHP_INT_MAX]);
        $worker = app(UsageSettlement::class); $this->assertSame([$this->usageBatch($f) => 'quarantined'], $worker->scan(10, $f['now']));
        $this->assertSame([], $worker->scan(10, $f['now'] + 300)); $this->assertSame(0, DB::table('tx_usage_entry')->count());
        $this->assertSame($original['receipt_id'], DB::table('tx_usage_batch')->value('receipt_id'));
        DB::table('tx_usage_period')->where('id', $f['period'])->update(['billable_bytes' => 0]);
        $worker->recover($this->usageBatch($f), 'ISOLATED_COUNTER_FIX_VERIFIED', $f['now'] + 1);
        $this->assertDatabaseHas('tx_usage_outbox',['kind' => 'usage.recover','status' => 'completed']);
        $this->assertSame([$this->usageBatch($f) => 'settled'], $worker->scan(10, $f['now'] + 2));
    }

    public function test_core_epoch_allocation_and_generation_recovery_preserve_old_receipts(): void
    {
        $f = $this->usageFixture(); $registry = app(UsageRegistry::class); $p = $this->usagePayload($f);
        $old = app(UsageReceipts::class)->submit($f['node']->id, $p, $f['now']);
        $this->assertSame((string) $f['source']->id, (string) $registry->source($f['node']->id, 'installation_test_0001', $f['now'])->id);
        config(['core_usage_v2.receipt_generation' => '2']);
        try { $registry->capability($f['node']->id, $f['now']); $this->fail('Unrecorded generation accepted'); }
        catch (UsageError $e) { $this->assertSame('CORE_INSTANCE_RECOVERY_REQUIRED', $e->errorCode); }
        $registry->advanceGeneration($f['node']->id, 'DATABASE_RESTORE_RECONCILED');
        $this->assertSame('2', $registry->capability($f['node']->id, $f['now'])['receipt_generation']);
        $this->assertSame($old, app(UsageReceipts::class)->submit($f['node']->id, $p, $f['now']));
        $new = $registry->source($f['node']->id, 'installation_test_0002', $f['now']);
        $this->assertSame('2', (string) $new->source_epoch); $this->assertSame(2, DB::table('tx_node_usage_source')->count());
    }

    public function test_http_routes_use_existing_node_auth_and_isolated_gate_and_raw_json_parser(): void
    {
        $f = $this->usageFixture(); $p = $this->usagePayload($f); admin_setting(['server_token' => 'usage-test-local-token']);
        $headers = ['Authorization' => 'Bearer usage-test-local-token','X-TX-Node-ID' => (string) $f['node']->id];
        $body = ['payload' => $p['payload'],'payload_digest' => $p['digest']];
        $this->postJson('/txapi/node/v2/usage/submit', $body)->assertStatus(401);
        config(['core_usage_v2.isolated_copy' => false]);
        $this->postJson('/txapi/node/v2/usage/submit', $body, $headers)->assertStatus(404);
        $this->postJson('/txapi/node/v1/handshake', [], $headers)->assertOk()->assertJsonMissingPath('data.usage_v2');
        config(['core_usage_v2.isolated_copy' => true]);
        $this->postJson('/txapi/node/v1/handshake', [], $headers)->assertOk()->assertJsonPath('data.usage_v2.source_epoch', '1');
        $this->postJson('/txapi/node/v2/usage/submit', $body, $headers)->assertOk()->assertJsonPath('data.receipt_status', 'persisted');
        $raw = '{"payload":{},"payload":{}}';
        $this->call('POST','/txapi/node/v2/usage/submit',[],[],[],array_merge(['CONTENT_TYPE' => 'application/json'],[
            'HTTP_AUTHORIZATION' => $headers['Authorization'],'HTTP_X_TX_NODE_ID' => $headers['X-TX-Node-ID']]),$raw)
            ->assertStatus(422)->assertJsonPath('error.code','USAGE_JSON_DUPLICATE_KEY');
    }

    public function test_node_source_snapshot_and_period_boundaries_fail_closed_without_partial_receipts(): void
    {
        $f = $this->usageFixture(); $service = app(UsageReceipts::class); $p = $this->usagePayload($f);
        foreach (['node_id','source_epoch'] as $field) {
            $invalid = $p; $invalid['payload'][$field] = '999999';
            try { $service->submit($f['node']->id, $invalid, $f['now']); $this->fail('Foreign identity accepted'); }
            catch (UsageError $e) { $this->assertContains($e->errorCode, ['USAGE_NODE_SCOPE','USAGE_SOURCE_UNKNOWN']); }
        }
        foreach (['access_subject_id','terms_id','usage_period_id','tariff_revision_id','auth_snapshot_id'] as $field) {
            $invalid = $p; $invalid['payload']['entries'][0][$field] = '999999';
            try { $service->submit($f['node']->id, $invalid, $f['now']); $this->fail('Foreign evidence accepted'); }
            catch (UsageError $e) { $this->assertContains($e->errorCode, ['USAGE_GRANT_SOURCE','USAGE_GRANT_OWNERSHIP_OR_COVERAGE']); }
        }
        $invalid = $p; $invalid['payload']['started_at'] = $f['now'] - 101;
        try { $service->submit($f['node']->id, $invalid, $f['now']); $this->fail('Lease boundary accepted'); }
        catch (UsageError $e) { $this->assertSame('USAGE_GRANT_OWNERSHIP_OR_COVERAGE', $e->errorCode); }
        DB::table('tx_usage_period')->where('id',$f['period'])->update(['boundary_kind' => 'legacy_boundary_unknown']);
        try { $service->submit($f['node']->id, $p, $f['now']); $this->fail('Unknown boundary accepted'); }
        catch (UsageError $e) { $this->assertSame('USAGE_GRANT_OWNERSHIP_OR_COVERAGE', $e->errorCode); }
        $this->assertSame(0, DB::table('tx_usage_batch')->count()); $this->assertSame(0, DB::table('tx_usage_outbox')->count());
    }

    public function test_quarantined_head_does_not_starve_later_outbox_work(): void
    {
        $f = $this->usageFixture(); $service = app(UsageReceipts::class);
        $service->submit($f['node']->id, $this->usagePayload($f), $f['now']);
        $service->submit($f['node']->id, $this->usagePayload($f, 'usage_batch_test_0002', 30), $f['now']);
        DB::table('tx_usage_batch')->where('id', $this->usageBatch($f))->update(['status' => 'quarantined','reason_code' => 'USAGE_FROZEN_EVIDENCE_CORRUPT']);
        $this->assertSame([$this->usageBatch($f, 1) => 'settled'], app(UsageSettlement::class)->scan(1, $f['now']));
        $this->assertDatabaseHas('tx_usage_batch',['id' => $this->usageBatch($f),'status' => 'quarantined']);
    }

    public function test_immutable_history_survives_legacy_node_deletion_and_additive_ddl_replay(): void
    {
        $f = $this->usageFixture(); $p = $this->usagePayload($f); $receipt = app(UsageReceipts::class)->submit($f['node']->id, $p, $f['now']);
        $migrationFiles = glob(database_path('migrations/2026_10_12_000003_*.php'));
        $this->assertCount(1, $migrationFiles); $migration = require $migrationFiles[0];
        $migration->up(); $f['node']->delete();
        $this->assertDatabaseHas('tx_node_usage_source',['id' => $f['source']->id]);
        $this->assertSame([$this->usageBatch($f) => 'settled'], app(UsageSettlement::class)->scan(10, $f['now']));
        $this->assertSame($receipt['receipt_id'], DB::table('tx_usage_batch')->value('receipt_id'));
        $this->expectExceptionMessage('Node usage history exists'); $migration->down();
    }

    private function usageBatch(array $fixture, int $offset = 0): int
    {
        return (int) DB::table('tx_usage_batch')->where('source_id', $fixture['source']->id)->orderBy('id')->offset($offset)->value('id');
    }

    public function test_receipt_timestamps_do_not_regress_when_worker_clock_moves_back(): void
    {
        $f = $this->usageFixture(); app(UsageReceipts::class)->submit($f['node']->id, $this->usagePayload($f), $f['now']);
        $worker = app(UsageSettlement::class); $batchId = $this->usageBatch($f); $fence = $worker->claim($batchId, $f['now'] - 10);
        $this->assertGreaterThanOrEqual($f['now'], (int) DB::table('tx_usage_batch')->where('id', $batchId)->value('updated_at'));
        $this->assertSame('settled', $worker->settle($batchId, $fence, $f['now'] - 9));
        $this->assertGreaterThanOrEqual($f['now'], (int) DB::table('tx_usage_batch')->where('id', $batchId)->value('settled_at'));
    }

    public function test_database_json_object_key_reordering_preserves_frozen_evidence_hash(): void
    {
        $f = $this->usageFixture(); app(UsageReceipts::class)->submit($f['node']->id, $this->usagePayload($f), $f['now']);
        $batchId = $this->usageBatch($f); $batch = DB::table('tx_usage_batch')->where('id', $batchId)->first();
        $entries = json_decode($batch->frozen_entries, true);
        foreach ($entries as &$entry) { krsort($entry, SORT_STRING); } unset($entry);
        DB::table('tx_usage_batch')->where('id', $batchId)->update(['frozen_entries' => json_encode($entries, JSON_PRETTY_PRINT)]);
        $this->assertSame([$batchId => 'settled'], app(UsageSettlement::class)->scan(10, $f['now']));
        $this->assertSame('11', (string) DB::table('tx_usage_period')->where('id', $f['period'])->value('billable_bytes'));
    }

    public function test_changed_frozen_evidence_content_is_quarantined_without_partial_charges(): void
    {
        $f = $this->usageFixture(); app(UsageReceipts::class)->submit($f['node']->id, $this->usagePayload($f), $f['now']);
        $batchId = $this->usageBatch($f); $batch = DB::table('tx_usage_batch')->where('id', $batchId)->first();
        $entries = json_decode($batch->frozen_entries, true); $entries[0]['billed_up_bytes'] = '999';
        DB::table('tx_usage_batch')->where('id', $batchId)->update(['frozen_entries' => json_encode($entries)]);
        $this->assertSame([$batchId => 'quarantined'], app(UsageSettlement::class)->scan(10, $f['now']));
        $this->assertDatabaseHas('tx_usage_batch',['id' => $batchId,'reason_code' => 'USAGE_FROZEN_EVIDENCE_CORRUPT']);
        $this->assertSame('0', (string) DB::table('tx_usage_period')->where('id', $f['period'])->value('billable_bytes'));
        $this->assertSame(0, DB::table('tx_usage_entry')->where('batch_id', $batchId)->count());
    }

    public function test_capability_rejects_ambiguous_runtime_user_subject_mapping(): void
    {
        $f = $this->usageFixture(); $registry = app(UsageRegistry::class);
        $otherSubject = DB::table('tx_access_subject')->insertGetId(['subscription_id' => $f['sub'], 'provider_id' => 'isolated-test',
            'provider_subject_ref' => 'other_subject_'.$f['sub'], 'status' => 'active', 'created_at' => $f['now'], 'updated_at' => $f['now']]);
        $registry->grant($f['source']->id, $otherSubject, $f['terms'], $f['period'], $f['tariff']->id, $f['now'] - 100, $f['now'] + 100);
        $this->expectExceptionMessage('USAGE_GRANT_MAPPING_AMBIGUOUS'); $registry->capability($f['node']->id, $f['now']);
    }

    public function test_subscription_suspension_without_effective_cutoff_rejects_new_receipts_but_preserves_replay(): void
    {
        $f = $this->usageFixture(); $p = $this->usagePayload($f); $receipts = app(UsageReceipts::class);
        $persisted = $receipts->submit($f['node']->id, $p, $f['now']);
        foreach (['suspended','revoked','pending'] as $status) {
            DB::table('tx_subscription')->where('id', $f['sub'])->update(['status' => $status]);
            $this->assertSame($persisted, $receipts->submit($f['node']->id, $p, $f['now']));
            try {
                $receipts->submit($f['node']->id, $this->usagePayload($f, 'usage_cutoff_unknown_0002', 30), $f['now']);
                $this->fail('Subscription cutoff was guessed');
            } catch (UsageError $e) { $this->assertSame('USAGE_SUBSCRIPTION_CUTOFF_UNKNOWN', $e->errorCode); }
        }
        $this->assertSame(1, DB::table('tx_usage_batch')->count());
    }

    public function test_expired_subscription_with_known_expiry_accepts_legal_historical_interval(): void
    {
        $f = $this->usageFixture(); DB::table('tx_subscription')->where('id', $f['sub'])->update([
            'status' => 'expired', 'expires_at' => $f['now'] - 20]);
        $receipt = app(UsageReceipts::class)->submit($f['node']->id, $this->usagePayload($f), $f['now']);
        $this->assertSame('persisted', $receipt['receipt_status']);
        $this->assertSame([$this->usageBatch($f) => 'settled'], app(UsageSettlement::class)->scan(10, $f['now']));
    }
}
