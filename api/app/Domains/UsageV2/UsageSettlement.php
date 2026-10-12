<?php

namespace App\Domains\UsageV2;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UsageSettlement
{
    public function __construct(private UsagePolicy $policy, private ?Closure $fault = null) {}

    /** SQL outbox is the durable work index; duplicate scans are safe. */
    public function scan(int $limit, int $now): array
    {
        $this->policy->requireIsolated();
        if ($limit < 1 || $limit > 1000) { throw new UsageError('USAGE_SCAN_LIMIT'); }
        $results = [];
        $eligible = DB::table('tx_usage_batch')->select('id')->whereIn('status', ['pending','processing','retry_wait'])
            ->where(fn ($q) => $q->whereNull('processing_lease_until')->orWhere('processing_lease_until', '<=', $now));
        $ids = DB::table('tx_usage_outbox')->where('kind', 'usage.accepted')->where('status', 'pending')
            ->whereIn('batch_id', $eligible)->orderBy('id')->limit($limit)->pluck('batch_id');
        foreach ($ids as $id) {
            $claim = $this->claim((int) $id, $now);
            if ($claim) { $results[(string) $id] = $this->settle((int) $id, $claim, $now); }
        }
        return $results;
    }

    public function claim(int $batchId, int $now): ?string
    {
        $this->policy->requireIsolated();
        return DB::transaction(function () use ($batchId, $now) {
            $batch = DB::table('tx_usage_batch')->where('id', $batchId)->lockForUpdate()->first();
            if (!$batch || in_array($batch->status, ['settled','quarantined'], true)
                || ($batch->processing_lease_until !== null && (int) $batch->processing_lease_until > $now)) { return null; }
            $fence = UsageMath::add((string) $batch->fencing_token, '1');
            DB::table('tx_usage_batch')->where('id', $batchId)->update(['status' => 'processing',
                'fencing_token' => $fence, 'attempts' => UsageMath::add((string) $batch->attempts, '1'),
                'processing_lease_until' => $now + (int) config('core_usage_v2.processing_lease_seconds'),
                'updated_at' => max($now, (int) $batch->updated_at, (int) $batch->accepted_at), 'reason_code' => null]);
            return $fence;
        }, 3);
    }

    public function settle(int $batchId, string $fence, int $now): string
    {
        $this->policy->requireIsolated();
        try {
            return DB::transaction(function () use ($batchId, $fence, $now) {
                $batch = DB::table('tx_usage_batch')->where('id', $batchId)->lockForUpdate()->first();
                if (!$batch || $batch->status !== 'processing' || (string) $batch->fencing_token !== $fence
                    || (int) $batch->processing_lease_until <= $now) { return 'stale'; }
                $entries = json_decode($batch->frozen_entries, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($entries) || !array_is_list($entries) || !$entries
                    || !hash_equals($batch->frozen_hash, UsageRegistry::hash($entries))
                    || !hash_equals($batch->payload_digest, 'sha256:'.hash('sha256', $batch->canonical_payload))) {
                    throw new UsageError('USAGE_FROZEN_EVIDENCE_CORRUPT');
                }
                $totals = []; $rawUp = '0'; $rawDown = '0';
                foreach ($entries as $entry) {
                    $pid = $entry['usage_period_id'];
                    $totals[$pid] ??= ['up' => '0','down' => '0','billable' => '0','subscription_id' => $entry['subscription_id']];
                    $totals[$pid]['up'] = UsageMath::add($totals[$pid]['up'], $entry['billed_up_bytes']);
                    $totals[$pid]['down'] = UsageMath::add($totals[$pid]['down'], $entry['billed_down_bytes']);
                    $totals[$pid]['billable'] = UsageMath::add($totals[$pid]['up'], $totals[$pid]['down']);
                    $rawUp = UsageMath::add($rawUp, $entry['raw_up_bytes']); $rawDown = UsageMath::add($rawDown, $entry['raw_down_bytes']);
                }
                // Every worker locks period IDs in the same numeric order.
                $subscriptions = array_unique(array_column($totals, 'subscription_id'));
                usort($subscriptions, fn ($a, $b) => UsagePayload::compare($a, $b));
                foreach ($subscriptions as $subscriptionId) {
                    if (!DB::table('tx_subscription')->where('id', $subscriptionId)->lockForUpdate()->exists()) { throw new UsageError('USAGE_FROZEN_SUBSCRIPTION_MISSING'); }
                }
                uksort($totals, fn ($a, $b) => UsagePayload::compare((string) $a, (string) $b));
                foreach ($totals as $pid => $total) {
                    $period = DB::table('tx_usage_period')->where('id', $pid)->lockForUpdate()->first();
                    if (!$period || (string) $period->subscription_id !== $total['subscription_id']) { throw new UsageError('USAGE_FROZEN_PERIOD_MISSING'); }
                    DB::table('tx_usage_period')->where('id', $pid)->update([
                        'upload_bytes' => UsageMath::add((string) $period->upload_bytes, $total['up']),
                        'download_bytes' => UsageMath::add((string) $period->download_bytes, $total['down']),
                        'billable_bytes' => UsageMath::add((string) $period->billable_bytes, $total['billable']),
                        'row_version' => UsageMath::add((string) $period->row_version, '1'), 'updated_at' => $now,
                    ]);
                }
                ($this->fault ?? static fn () => null)('after_period');
                foreach ($entries as $number => $entry) {
                    $ledger = array_intersect_key($entry, array_flip(['subscription_id','usage_period_id','access_subject_id','auth_snapshot_id',
                        'tariff_revision_id','terms_id','raw_up_bytes','raw_down_bytes','billed_up_bytes','billed_down_bytes','numerator','denominator','rounding_revision']));
                    DB::table('tx_usage_entry')->insert($ledger + ['batch_id' => $batchId, 'entry_no' => $number, 'created_at' => $now]);
                }
                DB::table('tx_node_usage_stat')->insertOrIgnore(['node_id' => $batch->node_id, 'raw_up_bytes' => '0','raw_down_bytes' => '0','updated_at' => $now]);
                $stat = DB::table('tx_node_usage_stat')->where('node_id', $batch->node_id)->lockForUpdate()->first();
                DB::table('tx_node_usage_stat')->where('node_id', $batch->node_id)->update([
                    'raw_up_bytes' => UsageMath::add((string) $stat->raw_up_bytes, $rawUp),
                    'raw_down_bytes' => UsageMath::add((string) $stat->raw_down_bytes, $rawDown), 'updated_at' => $now,
                ]);
                ($this->fault ?? static fn () => null)('after_ledger');
                DB::table('tx_usage_batch')->where('id', $batchId)->update(['status' => 'settled', 'settled_at' => max($now, (int) $batch->accepted_at),
                    'updated_at' => max($now, (int) $batch->updated_at, (int) $batch->accepted_at), 'processing_lease_until' => null, 'reason_code' => null]);
                DB::table('tx_usage_outbox')->where('batch_id', $batchId)->where('kind', 'usage.accepted')->update(['status' => 'completed', 'completed_at' => $now]);
                DB::table('tx_usage_outbox')->insert(['batch_id' => $batchId, 'event_key' => 'usage.settled:'.$batchId, 'kind' => 'usage.settled',
                    'status' => 'pending', 'body' => json_encode(['receipt_id' => $batch->receipt_id, 'payload_digest' => $batch->payload_digest], JSON_THROW_ON_ERROR), 'created_at' => $now]);
                ($this->fault ?? static fn () => null)('before_commit');
                return 'settled';
            }, 3);
        } catch (Throwable $error) {
            $quarantine = $error instanceof UsageError || $error instanceof \JsonException;
            $state = $quarantine ? 'quarantined' : 'retry_wait';
            DB::transaction(function () use ($batchId, $fence, $now, $error, $state, $quarantine) {
                $batch = DB::table('tx_usage_batch')->where('id', $batchId)->lockForUpdate()->first();
                if ($batch && $batch->status === 'processing' && (string) $batch->fencing_token === $fence) {
                    DB::table('tx_usage_batch')->where('id', $batchId)->update(['status' => $state,
                        'reason_code' => $error instanceof UsageError ? $error->errorCode : ($quarantine ? 'USAGE_FROZEN_EVIDENCE_CORRUPT' : 'USAGE_WORKER_RETRY'),
                        'processing_lease_until' => $quarantine ? null : $now + min(300, 2 ** min(8, (int) $batch->attempts)),
                        'updated_at' => max($now, (int) $batch->updated_at, (int) $batch->accepted_at)]);
                }
            }, 3);
            return $state;
        }
    }

    public function recover(int $batchId, string $reason, int $now): void
    {
        $this->policy->requireIsolated();
        if (!preg_match('/^[A-Z][A-Z0-9_]{2,79}$/D', $reason)) { throw new UsageError('USAGE_RECOVERY_REASON_INVALID'); }
        DB::transaction(function () use ($batchId, $reason, $now) {
            $batch = DB::table('tx_usage_batch')->where('id', $batchId)->lockForUpdate()->first();
            if (!$batch || $batch->status !== 'quarantined') { throw new UsageError('USAGE_BATCH_NOT_QUARANTINED', 409); }
            DB::table('tx_usage_outbox')->insert(['batch_id' => $batchId, 'event_key' => 'usage.recover:'.$batchId.':'.($batch->fencing_token),
                'kind' => 'usage.recover', 'status' => 'completed', 'body' => json_encode(['reason_code' => $reason, 'previous_reason_code' => $batch->reason_code], JSON_THROW_ON_ERROR),
                'created_at' => $now, 'completed_at' => $now]);
            DB::table('tx_usage_batch')->where('id', $batchId)->update(['status' => 'pending', 'reason_code' => null,
                'processing_lease_until' => null, 'updated_at' => max($now, (int) $batch->updated_at, (int) $batch->accepted_at)]);
        }, 3);
    }
}
