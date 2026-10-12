<?php

namespace App\Domains\UsageV2;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Redis/queue dispatch is never a prerequisite for a persisted ACK. */
final class UsageReceipts
{
    public function __construct(private UsagePolicy $policy, private UsageRegistry $registry, private ?Closure $fault = null,
        private ?UsageTermsBoundary $boundaries = null) {}

    public function submit(int $nodeId, array $parsed, int $now): array
    {
        $this->policy->requireIsolated(); $p = $parsed['payload'];
        if ((string) $nodeId !== $p['node_id']) { throw new UsageError('USAGE_NODE_SCOPE', 403); }
        try { return DB::transaction(function () use ($nodeId, $parsed, $p, $now) {
            $source = DB::table('tx_node_usage_source')->where('node_id', $nodeId)->where('source_epoch', $p['source_epoch'])->lockForUpdate()->first();
            if (!$source) { throw new UsageError('USAGE_SOURCE_UNKNOWN', 403); }
            // Historical replay precedes current generation/authorization checks.
            $existing = $this->find($p);
            if ($existing) { return $this->matching($existing, $parsed['digest']); }
            $this->registry->identity($source);
            if ($p['ended_at'] > $now + (int) config('core_usage_v2.max_future_seconds')
                || $p['ended_at'] < $now - (int) config('core_usage_v2.max_late_report_seconds')) { throw new UsageError('USAGE_INTERVAL_OUTSIDE_RETENTION'); }
            if (DB::table('tx_usage_batch')->where('source_id', $source->id)->where('started_at', '<', $p['ended_at'])
                ->where('ended_at', '>', $p['started_at'])->exists()) { throw new UsageError('USAGE_INTERVAL_OVERLAP', 409); }
            $frozen = []; $rawUp = '0'; $rawDown = '0'; $billed = '0';
            foreach ($p['entries'] as $entry) {
                $evidence = $this->freeze($source, $entry, $p); $frozen[] = $evidence;
                $rawUp = UsageMath::add($rawUp, $evidence['raw_up_bytes']);
                $rawDown = UsageMath::add($rawDown, $evidence['raw_down_bytes']);
                $billed = UsageMath::add($billed, $evidence['billed_up_bytes'], $evidence['billed_down_bytes']);
            }
            $encoded = json_encode($frozen, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $id = DB::table('tx_usage_batch')->insertGetId([
                'source_id' => $source->id, 'node_id' => $nodeId, 'source_epoch' => $p['source_epoch'], 'batch_id' => $p['batch_id'],
                'core_instance_id' => $source->core_instance_id, 'receipt_generation' => $source->receipt_generation,
                'receipt_id' => 'receipt_'.Str::uuid(), 'payload_digest' => $parsed['digest'], 'canonical_payload' => $parsed['canonical'],
                'frozen_entries' => $encoded, 'frozen_hash' => UsageRegistry::hash($frozen),
                'started_at' => $p['started_at'], 'ended_at' => $p['ended_at'], 'status' => 'pending', 'accepted_at' => $now, 'updated_at' => $now,
            ]);
            ($this->fault ?? static fn () => null)('after_payload');
            DB::table('tx_usage_outbox')->insert([
                'batch_id' => $id, 'event_key' => 'usage.accepted:'.$id, 'kind' => 'usage.accepted', 'status' => 'pending',
                'body' => json_encode(['batch_row_id' => (string) $id, 'payload_digest' => $parsed['digest']], JSON_THROW_ON_ERROR), 'created_at' => $now,
            ]);
            ($this->fault ?? static fn () => null)('after_outbox');
            return self::receipt(DB::table('tx_usage_batch')->where('id', $id)->first());
        }, 3); } catch (UsageError $error) {
            if ($error->errorCode === 'BATCH_PAYLOAD_CONFLICT') { $this->conflictAudit($p, $parsed['digest'], $now); }
            throw $error;
        }
    }

    public function query(int $nodeId, array $query): array
    {
        $this->policy->requireIsolated();
        if ((string) $nodeId !== $query['node_id']) { throw new UsageError('USAGE_NODE_SCOPE', 403); }
        $batch = $this->find($query);
        if (!$batch) { throw new UsageError('BATCH_NOT_FOUND', 404); }
        try { return $this->matching($batch, $query['payload_digest']); }
        catch (UsageError $error) {
            if ($error->errorCode === 'BATCH_PAYLOAD_CONFLICT') { $this->conflictAudit($query, $query['payload_digest'], time()); }
            throw $error;
        }
    }

    private function find(array $identity): ?object
    {
        return DB::table('tx_usage_batch')->where('node_id', $identity['node_id'])->where('source_epoch', $identity['source_epoch'])
            ->where('batch_id', $identity['batch_id'])->lockForUpdate()->first();
    }

    private function matching(object $batch, string $digest): array
    {
        if (!hash_equals($batch->payload_digest, $digest)) { throw new UsageError('BATCH_PAYLOAD_CONFLICT', 409); }
        return self::receipt($batch);
    }

    private function conflictAudit(array $identity, string $digest, int $now): void
    {
        $batch = $this->find($identity);
        if (!$batch) { return; }
        DB::table('tx_usage_outbox')->insertOrIgnore([
            'batch_id' => $batch->id, 'event_key' => 'usage.conflict:'.$batch->id.':'.hash('sha256', $digest),
            'kind' => 'usage.conflict', 'status' => 'completed',
            'body' => json_encode(['reason_code' => 'BATCH_PAYLOAD_CONFLICT', 'supplied_digest' => $digest, 'original_digest' => $batch->payload_digest], JSON_THROW_ON_ERROR),
            'created_at' => $now, 'completed_at' => $now,
        ]);
    }

    private function freeze(object $source, array $entry, array $payload): array
    {
        $grant = DB::table('tx_auth_snapshot')->where('id', $entry['auth_snapshot_id'])->lockForUpdate()->first();
        if (!$grant || (string) $grant->source_id !== (string) $source->id) { throw new UsageError('USAGE_GRANT_SOURCE'); }
        foreach (['access_subject_id','tariff_revision_id','terms_id','usage_period_id'] as $key) {
            if ((string) $grant->$key !== $entry[$key]) { throw new UsageError('USAGE_GRANT_OWNERSHIP_OR_COVERAGE'); }
        }
        $subject = DB::table('tx_access_subject')->where('id', $grant->access_subject_id)->lockForUpdate()->first();
        $sub = DB::table('tx_subscription')->where('id', $grant->subscription_id)->lockForUpdate()->first();
        $terms = DB::table('tx_subscription_terms')->where('id', $grant->terms_id)->lockForUpdate()->first();
        $period = DB::table('tx_usage_period')->where('id', $grant->usage_period_id)->lockForUpdate()->first();
        $tariff = DB::table('tx_tariff_revision')->where('id', $grant->tariff_revision_id)->lockForUpdate()->first();
        $start = $payload['started_at']; $end = $payload['ended_at'];
        if ($sub && $subject && $sub->status !== 'active' && $sub->ended_at === null
            && $grant->revoked_at === null && $subject->revoked_at === null
            && !($sub->status === 'expired' && $sub->expires_at !== null)) {
            throw new UsageError('USAGE_SUBSCRIPTION_CUTOFF_UNKNOWN');
        }
        if (!$subject || !$sub || !$terms || !$period || !$tariff
            || (string) $sub->user_id !== (string) $grant->user_id || (int) $tariff->node_id !== (int) $source->node_id
            || (string) $subject->subscription_id !== (string) $sub->id || (string) $terms->subscription_id !== (string) $sub->id
            || (string) $period->subscription_id !== (string) $sub->id
            || $period->boundary_kind !== 'known' || $period->starts_at === null || $period->ends_at === null
            || !UsageRegistry::covers($start, $end, $grant->issued_at, $grant->lease_expires_at)
            || !UsageRegistry::covers($start, $end, $period->starts_at, $period->ends_at)
            || !UsageRegistry::covers($start, $end, $tariff->effective_from, $tariff->effective_until)
            || !UsageRegistry::covers($start, $end, $sub->starts_at, $sub->expires_at)
            || ($sub->ended_at !== null && $end > (int) $sub->ended_at)
            || ($grant->revoked_at !== null && $end > (int) $grant->revoked_at)
            || ($subject->revoked_at !== null && $end > (int) $subject->revoked_at)) {
            throw new UsageError('USAGE_GRANT_OWNERSHIP_OR_COVERAGE');
        }
        // A revoked subject can report traffic strictly within its former authorization.
        // A changed auth version without an effective revocation timestamp cannot establish a safe interval.
        if ((string) $subject->auth_version !== (string) $grant->auth_version && $subject->revoked_at === null) { throw new UsageError('USAGE_AUTH_VERSION_AMBIGUOUS'); }
        if ($subject->status !== 'active' && $subject->revoked_at === null) { throw new UsageError('USAGE_REVOCATION_TIME_UNKNOWN'); }
        if ($tariff->rounding_revision !== 'floor-per-direction-v1') { throw new UsageError('USAGE_ROUNDING_UNSUPPORTED'); }
        $boundary = ($this->boundaries ?? app(UsageTermsBoundary::class))->freeze((string) $sub->id, (string) $terms->id, $period, $start, $end);
        return $entry + $boundary + [
            'subscription_id' => (string) $grant->subscription_id, 'user_id' => (string) $grant->user_id,
            'billed_up_bytes' => UsageMath::floor($entry['raw_up_bytes'], (string) $tariff->numerator, (string) $tariff->denominator),
            'billed_down_bytes' => UsageMath::floor($entry['raw_down_bytes'], (string) $tariff->numerator, (string) $tariff->denominator),
            'numerator' => (string) $tariff->numerator, 'denominator' => (string) $tariff->denominator, 'rounding_revision' => $tariff->rounding_revision,
            'grant_hash' => $grant->content_hash, 'tariff_hash' => $tariff->content_hash, 'terms_hash' => $terms->content_hash,
            'grant_issued_at' => (int) $grant->issued_at, 'grant_lease_expires_at' => (int) $grant->lease_expires_at,
            'period_started_at' => (int) $period->starts_at, 'period_ended_at' => (int) $period->ends_at,
            'auth_version' => (string) $grant->auth_version, 'revocation_version' => (string) $grant->revocation_version,
        ];
    }

    public static function receipt(object $batch): array
    {
        $receipt = ['protocol_version' => 2, 'core_instance_id' => $batch->core_instance_id,
            'receipt_generation' => (string) $batch->receipt_generation, 'node_id' => (string) $batch->node_id,
            'source_epoch' => (string) $batch->source_epoch, 'batch_id' => $batch->batch_id, 'payload_digest' => $batch->payload_digest,
            'receipt_status' => 'persisted', 'settlement_status' => $batch->status, 'accepted_at' => (int) $batch->accepted_at,
            'receipt_id' => $batch->receipt_id, 'updated_at' => (int) $batch->updated_at];
        if ($batch->settled_at !== null) { $receipt['settled_at'] = (int) $batch->settled_at; }
        if ($batch->reason_code !== null) { $receipt['reason_code'] = $batch->reason_code; }
        return $receipt;
    }
}
