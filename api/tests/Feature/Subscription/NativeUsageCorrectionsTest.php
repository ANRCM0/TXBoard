<?php

namespace Tests\Feature\Subscription;

use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\UsageV2\NativeUsageCorrections;
use App\Domains\UsageV2\UsageError;
use App\Domains\UsageV2\UsagePayload;
use App\Domains\UsageV2\UsagePolicy;
use App\Domains\UsageV2\UsageReceipts;
use App\Domains\UsageV2\UsageSettlement;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeRuntimeTestCase;
use Tests\Support\NativeWorkflowActors;

final class NativeUsageCorrectionsTest extends NativeRuntimeTestCase
{
    use NativeWorkflowActors;

    public function test_correction_preserves_raw_evidence_reservations_and_late_settlement_and_replays_once(): void
    {
        $this->activate(); [$user] = $this->subscribe(); $admin = $this->workflowActor(true, [NativeUsageCorrections::PERMISSION]); $this->authenticateWorkflowActor($admin);
        $snapshot = $this->snapshot(); $this->submit($snapshot, 'correction-first', '3', '5');
        $this->assertSame(['settled'], array_values(app(UsageSettlement::class)->scan(10, time() + 2)));
        $original = DB::table('tx_usage_entry')->first(); $nodeStat = DB::table('tx_node_usage_stat')->first();
        $this->submit($snapshot, 'correction-pending', '2', '2', 1);
        $this->assertSame('6', (string) DB::table('tx_native_usage_reservation')->value('reserved_bytes'));
        while (time() <= $snapshot['issued_at'] + 2) usleep(100000);
        $owner = app(NativeUsageCorrections::class); $entry = $owner->entries($admin->id, $user->id)['items'][0]; $key = $this->workflowKey();
        $input = ['entry_id' => $entry['entry_id'], 'expected_entry_revision' => $entry['entry_revision'], 'expected_period_version' => $entry['period_version'],
            'billed_up_bytes' => '2', 'billed_down_bytes' => '3', 'reason' => 'verified_case'];
        $result = $owner->create($admin->id, $user->id, $input, $key);
        $this->assertSame($result, $owner->create($admin->id, $user->id, $input, $key));
        $this->assertSame('5', (string) DB::table('tx_usage_period')->value('billable_bytes')); $this->assertSame('6', (string) DB::table('tx_native_usage_reservation')->value('reserved_bytes'));
        $this->assertEquals($original, DB::table('tx_usage_entry')->where('id', $original->id)->first()); $this->assertEquals($nodeStat, DB::table('tx_node_usage_stat')->first());
        $this->reject('USAGE_CORRECTION_VERSION_CONFLICT', fn () => $owner->create($admin->id, $user->id, $input, $this->workflowKey()));
        $this->assertSame(['settled'], array_values(app(UsageSettlement::class)->scan(10, time() + 2)));
        $this->assertSame('11', (string) DB::table('tx_usage_period')->value('billable_bytes')); $this->assertSame(0, DB::table('tx_native_usage_reservation')->count());
        $this->assertSame(2, DB::table('tx_usage_entry')->count()); $this->assertSame(1, DB::table('tx_native_usage_correction')->count());
        $this->assertSame($result, $owner->history($admin->id, $user->id)['items'][0]);
        $this->assertSame('5', (string) DB::table('tx_node_usage_stat')->value('raw_up_bytes')); $this->assertSame('7', (string) DB::table('tx_node_usage_stat')->value('raw_down_bytes'));
        $this->assertSame($result, $owner->create($admin->id, $user->id, $input, $key));
        DB::table('tx_internal_actor_grant')->where('user_id', $admin->id)->delete();
        $this->workflowHttpFailure(403, fn () => $owner->create($admin->id, $user->id, $input, $key));
    }

    public function test_projection_and_signed_correction_tampering_are_refused(): void
    {
        $this->activate(); [$user] = $this->subscribe(); $admin = $this->workflowActor(true, [NativeUsageCorrections::PERMISSION]); $this->authenticateWorkflowActor($admin);
        $snapshot = $this->snapshot(); $this->submit($snapshot, 'correction-tamper', '3', '5'); app(UsageSettlement::class)->scan(10, time() + 2);
        while (time() <= $snapshot['issued_at'] + 1) usleep(100000);
        $owner = app(NativeUsageCorrections::class); $entry = $owner->entries($admin->id, $user->id)['items'][0];
        $owner->create($admin->id, $user->id, ['entry_id' => $entry['entry_id'], 'expected_entry_revision' => $entry['entry_revision'],
            'expected_period_version' => $entry['period_version'], 'billed_up_bytes' => '1', 'billed_down_bytes' => '1', 'reason' => 'verified_case'], $this->workflowKey());
        DB::table('tx_usage_period')->update(['billable_bytes' => '3']);
        $this->reject('USAGE_CORRECTION_PROJECTION_MISMATCH', fn () => $owner->entries($admin->id, $user->id));
        DB::table('tx_usage_period')->update(['billable_bytes' => '2']); DB::table('tx_native_usage_correction')->update(['content_hash' => str_repeat('0', 64)]);
        $this->reject('USAGE_CORRECTION_EVIDENCE_INVALID', fn () => $owner->entries($admin->id, $user->id));
    }

    public function test_real_core_gateway_requires_observed_entry_and_period_and_keeps_permanent_receipt(): void
    {
        $this->activate(); [$user] = $this->subscribe(); $admin = $this->workflowActor(true, [NativeUsageCorrections::PERMISSION]);
        $snapshot = $this->snapshot(); $this->submit($snapshot, 'correction-browser', '3', '5'); app(UsageSettlement::class)->scan(10, time() + 2);
        while (time() <= $snapshot['issued_at'] + 1) usleep(100000);
        config(['browser.enabled' => true, 'browser.admin_enabled' => true, 'browser.origin' => 'https://core.example.test', 'browser.rate_store' => 'array']);
        admin_setting(['secure_path' => 'usage_correction']);
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('browser', ['*'])->plainTextToken,
            'X-Core-Admin-Path' => 'usage_correction', 'Origin' => 'https://core.example.test'];
        $invoke = fn (string $operation, array $input, ?string $key = null) => $this->postJson('https://core.example.test/gateway/v1/core/admin/operations/'.$operation,
            ['input' => $input], $headers + ($key ? ['Idempotency-Key' => $key] : []));
        $entry = $invoke('usage.admin-settled-entries.list', ['user_id' => (string) $user->id])->assertOk()->json('data.items.0');
        $input = ['user_id' => (string) $user->id, 'entry_id' => $entry['entry_id'], 'expected_entry_revision' => $entry['entry_revision'],
            'expected_period_version' => $entry['period_version'], 'billed_up_bytes' => '1', 'billed_down_bytes' => '2', 'reason' => 'gateway_case'];
        $key = $this->workflowKey(); $result = $invoke('usage.admin-corrections.create', $input, $key)->assertOk()->json('data');
        DB::table('tx_internal_request')->where('operation', 'usage.admin-corrections.create')->delete();
        $invoke('usage.admin-corrections.create', $input, $key)->assertOk()->assertJsonPath('data', $result);
        $invoke('usage.admin-corrections.create', $input, $this->workflowKey())->assertStatus(409);
        $invoke('usage.admin-corrections.create', $input + ['u' => '0'], $this->workflowKey())->assertStatus(422);
        $invoke('usage.admin-corrections.list', ['user_id' => (string) $user->id])->assertOk()->assertJsonPath('data.items.0.correction_id', $result['correction_id']);
        DB::table('tx_internal_actor_grant')->where('user_id', $admin->id)->delete();
        $invoke('usage.admin-corrections.create', $input, $key)->assertForbidden();
    }

    public function test_projection_beyond_ten_thousand_entries_allows_a_later_original_settlement(): void
    {
        $this->activate(); [$user] = $this->subscribe();
        $admin = $this->workflowActor(true, [NativeUsageCorrections::PERMISSION]); $this->authenticateWorkflowActor($admin);
        $snapshot = $this->snapshot(); $this->submit($snapshot, 'large-period-original', '3', '5');
        app(UsageSettlement::class)->scan(10, time() + 2);
        while (time() <= $snapshot['issued_at'] + 1) usleep(100000);
        $owner = app(NativeUsageCorrections::class); $entry = $owner->entries($admin->id, $user->id)['items'][0];
        $owner->create($admin->id, $user->id, ['entry_id' => $entry['entry_id'], 'expected_entry_revision' => $entry['entry_revision'],
            'expected_period_version' => $entry['period_version'], 'billed_up_bytes' => '2', 'billed_down_bytes' => '3', 'reason' => 'large_period_case'], $this->workflowKey());
        // Seed independently identified, internally consistent settled evidence without 10,000 worker invocations.
        $originalBatch = (array) DB::table('tx_usage_batch')->first(); $originalEntry = (array) DB::table('tx_usage_entry')->first();
        $batchStart = (int) DB::table('tx_usage_batch')->max('id'); $entryStart = (int) DB::table('tx_usage_entry')->max('id');
        DB::transaction(function () use ($originalBatch, $originalEntry, $batchStart, $entryStart): void {
            for ($offset = 0; $offset < 10000; $offset += 250) {
                $batches = []; $entries = [];
                for ($index = $offset + 1; $index <= $offset + 250; ++$index) {
                    $batch = $originalBatch; $batch['id'] = $batchStart + $index;
                    $batch['batch_id'] = 'large-period-'.$index; $batch['receipt_id'] = 'large-period-receipt-'.$index;
                    $payload = json_decode($batch['canonical_payload'], true, 32, JSON_THROW_ON_ERROR); $payload['batch_id'] = $batch['batch_id'];
                    $batch['canonical_payload'] = ProviderCodec::canonical($payload); $batch['payload_digest'] = 'sha256:'.hash('sha256', $batch['canonical_payload']);
                    $batches[] = $batch; $row = $originalEntry; $row['id'] = $entryStart + $index; $row['batch_id'] = $batch['id']; $entries[] = $row;
                }
                DB::table('tx_usage_batch')->insert($batches); DB::table('tx_usage_entry')->insert($entries);
            }
            DB::table('tx_usage_period')->update(['upload_bytes' => '40002', 'download_bytes' => '70003', 'billable_bytes' => '110005']);
            DB::table('tx_node_usage_stat')->update(['raw_up_bytes' => '30003', 'raw_down_bytes' => '50005']);
        });
        $this->submit($snapshot, 'large-period-pending', '2', '2', 1);
        $this->assertSame(['settled'], array_values(app(UsageSettlement::class)->scan(10, time() + 2)));
        $this->assertSame(10002, DB::table('tx_usage_entry')->count());
        $this->assertSame('110011', (string) DB::table('tx_usage_period')->value('billable_bytes'));
        $this->assertSame(0, DB::table('tx_usage_batch')->where('status', 'quarantined')->count());
    }

    public function test_temporary_correction_failure_retries_instead_of_quarantining_accepted_usage(): void
    {
        $this->activate(); $this->subscribe(); $snapshot = $this->snapshot(); $this->submit($snapshot, 'correction-temporary', '2', '2');
        $worker = new UsageSettlement(app(UsagePolicy::class), static function (string $phase): void {
            if ($phase === 'after_period') throw new UsageError('USAGE_CORRECTION_CAPABILITY_UNAVAILABLE', 503);
        });
        $this->assertSame(['retry_wait'], array_values($worker->scan(10, time() + 2)));
        $this->assertSame(0, DB::table('tx_usage_entry')->count());
        $this->assertSame('0', (string) DB::table('tx_usage_period')->value('billable_bytes'));
        $this->assertSame(1, DB::table('tx_native_usage_reservation')->count());
        $this->assertSame(['settled'], array_values(app(UsageSettlement::class)->scan(10, time() + 100)));
        $this->assertSame(1, DB::table('tx_usage_entry')->count());
    }

    private function submit(array $snapshot, string $batch, string $up, string $down, int $offset = 0): void
    {
        $grant = $snapshot['usage_v2']['grants'][0];
        $payload = ['protocol_version' => 2, 'node_id' => (string) $this->node->id, 'source_epoch' => $snapshot['usage_v2']['source_epoch'], 'batch_id' => $batch,
            'started_at' => $snapshot['issued_at'] + $offset, 'ended_at' => $snapshot['issued_at'] + $offset + 1,
            'entries' => [array_intersect_key($grant, array_flip(['access_subject_id', 'auth_snapshot_id', 'tariff_revision_id', 'terms_id', 'usage_period_id'])) + ['raw_up_bytes' => $up, 'raw_down_bytes' => $down]]];
        $submission = app(UsagePayload::class)->submission(json_encode(['payload' => $payload, 'payload_digest' => 'sha256:'.hash('sha256', ProviderCodec::canonical($payload))], JSON_THROW_ON_ERROR));
        app(UsageReceipts::class)->submit((int) $this->node->id, $submission, $snapshot['issued_at'] + $offset + 2);
    }
    private function reject(string $code, \Closure $operation): void
    {
        try { $operation(); $this->fail('Expected '.$code); } catch (UsageError $error) { $this->assertSame($code, $error->errorCode); }
    }
}
