<?php

namespace App\Domains\UsageV2;

use Illuminate\Support\Facades\DB;

/** Isolated fixtures only. Epochs, tariffs and grants are always assigned by Core. */
final class UsageRegistry
{
    public function __construct(private UsagePolicy $policy, private ?UsageTermsBoundary $boundaries = null) {}

    public function source(int $nodeId, string $installationRef, int $now): object
    {
        $this->policy->requireIsolated();
        if (!preg_match('/^[A-Za-z0-9:_-]{8,80}$/D', $installationRef)) { throw new UsageError('USAGE_INSTALLATION_INVALID'); }
        return DB::transaction(function () use ($nodeId, $installationRef, $now) {
            // Lock a stable node row even when this is its first source allocation.
            if (!DB::table('tx_server')->where('id', $nodeId)->lockForUpdate()->exists()) { throw new UsageError('USAGE_NODE_MISSING', 404); }
            $existing = DB::table('tx_node_usage_source')->where('node_id', $nodeId)->where('installation_ref', $installationRef)->lockForUpdate()->first();
            if ($existing) { $this->identity($existing); return $existing; }
            $previous = DB::table('tx_node_usage_source')->where('node_id', $nodeId)->orderByDesc('source_epoch')->lockForUpdate()->first();
            if ($previous && $previous->core_instance_id !== config('core_usage_v2.core_instance_id')) { throw new UsageError('CORE_INSTANCE_RECOVERY_REQUIRED', 409); }
            $epoch = UsageMath::add((string) ($previous->source_epoch ?? '0'), '1');
            DB::table('tx_node_usage_source')->where('node_id', $nodeId)->update(['current' => false]);
            $id = DB::table('tx_node_usage_source')->insertGetId([
                'node_id' => $nodeId, 'source_epoch' => $epoch, 'installation_ref' => $installationRef,
                'core_instance_id' => config('core_usage_v2.core_instance_id'),
                'receipt_generation' => config('core_usage_v2.receipt_generation'), 'current' => true, 'created_at' => $now,
            ]);
            return DB::table('tx_node_usage_source')->where('id', $id)->first();
        }, 3);
    }

    public function tariff(int $nodeId, string $numerator, string $denominator, int $from, int $until): object
    {
        $this->policy->requireIsolated(); UsagePayload::decimal($numerator, true); UsagePayload::decimal($denominator, false);
        UsagePayload::time($from); UsagePayload::time($until);
        if ($from >= $until || !DB::table('tx_server')->where('id', $nodeId)->exists()) { throw new UsageError('USAGE_TARIFF_INVALID'); }
        $data = ['node_id' => $nodeId, 'numerator' => $numerator, 'denominator' => $denominator,
            'effective_from' => $from, 'effective_until' => $until, 'rounding_revision' => 'floor-per-direction-v1'];
        $id = DB::table('tx_tariff_revision')->insertGetId($data + ['content_hash' => self::hash($data), 'created_at' => time()]);
        return DB::table('tx_tariff_revision')->where('id', $id)->first();
    }

    public function grant(int $sourceId, int $subjectId, int $termsId, int $periodId, int $tariffId, int $issued, int $expires): object
    {
        $this->policy->requireIsolated(); UsagePayload::time($issued); UsagePayload::time($expires);
        if ($issued >= $expires) { throw new UsageError('USAGE_LEASE_INVALID'); }
        return DB::transaction(function () use ($sourceId, $subjectId, $termsId, $periodId, $tariffId, $issued, $expires) {
            $source = DB::table('tx_node_usage_source')->where('id', $sourceId)->lockForUpdate()->first();
            if (!$source || !$source->current) { throw new UsageError('USAGE_SOURCE_INVALID'); } $this->identity($source);
            $subject = DB::table('tx_access_subject')->where('id', $subjectId)->lockForUpdate()->first();
            $sub = $subject ? DB::table('tx_subscription')->where('id', $subject->subscription_id)->lockForUpdate()->first() : null;
            $terms = DB::table('tx_subscription_terms')->where('id', $termsId)->lockForUpdate()->first();
            $period = DB::table('tx_usage_period')->where('id', $periodId)->lockForUpdate()->first();
            $tariff = DB::table('tx_tariff_revision')->where('id', $tariffId)->lockForUpdate()->first();
            if (!$subject || !$sub || !$terms || !$period || !$tariff || $subject->status !== 'active'
                || $subject->revoked_at !== null || $sub->status !== 'active'
                || (string) $terms->subscription_id !== (string) $sub->id || (string) $period->subscription_id !== (string) $sub->id
                || (int) $tariff->node_id !== (int) $source->node_id
                || $period->boundary_kind !== 'known' || $period->starts_at === null || $period->ends_at === null
                || !self::covers($issued, $expires, $sub->starts_at, $sub->expires_at)
                || !self::covers($issued, $expires, $period->starts_at, $period->ends_at)
                || !self::covers($issued, $expires, $tariff->effective_from, $tariff->effective_until)) {
                throw new UsageError('USAGE_GRANT_OWNERSHIP_OR_COVERAGE');
            }
            ($this->boundaries ?? app(UsageTermsBoundary::class))->freeze((string) $sub->id, (string) $termsId, $period, $issued, $expires);
            $data = ['source_id' => $sourceId, 'user_id' => (int) $sub->user_id, 'access_subject_id' => $subjectId,
                'subscription_id' => (string) $sub->id, 'terms_id' => $termsId, 'usage_period_id' => $periodId,
                'tariff_revision_id' => $tariffId, 'auth_version' => (string) $subject->auth_version,
                'revocation_version' => (string) $subject->revocation_fact_version, 'issued_at' => $issued, 'lease_expires_at' => $expires];
            $id = DB::table('tx_auth_snapshot')->insertGetId($data + ['content_hash' => self::hash($data), 'created_at' => time()]);
            return DB::table('tx_auth_snapshot')->where('id', $id)->first();
        }, 3);
    }

    public function capability(int $nodeId, int $now): ?array
    {
        if (!$this->policy->enabled()) { return null; }
        $this->policy->requireIsolated();
        return DB::transaction(fn () => $this->capabilityLocked($nodeId, $now), 3);
    }

    private function capabilityLocked(int $nodeId, int $now): ?array
    {
        $source = DB::table('tx_node_usage_source')->where('node_id', $nodeId)->where('current', true)->sharedLock()->first();
        if (!$source) { return null; } $this->identity($source);
        $grants = []; $owners = [];
        $active = DB::table('tx_auth_snapshot')->where('source_id', $source->id)->whereNull('revoked_at')
            ->where('issued_at', '<=', $now)->where('lease_expires_at', '>', $now)->orderBy('id')->limit(10001)->sharedLock()->get();
        if ($active->count() > 10000) { throw new UsageError('USAGE_GRANT_LIMIT', 503); }
        foreach ($active as $grant) {
            $subject = DB::table('tx_access_subject')->where('id', $grant->access_subject_id)->sharedLock()->first();
            $sub = DB::table('tx_subscription')->where('id', $grant->subscription_id)->sharedLock()->first();
            if (!$subject || !$sub || $subject->status !== 'active' || $subject->revoked_at !== null || $sub->status !== 'active'
                || ($sub->expires_at !== null && (int) $sub->expires_at <= $now) || $sub->ended_at !== null
                || (string) $subject->auth_version !== (string) $grant->auth_version
                || (string) $subject->revocation_fact_version !== (string) $grant->revocation_version) { continue; }
            $period = DB::table('tx_usage_period')->where('id', $grant->usage_period_id)->sharedLock()->first();
            if (!$period) { throw new UsageError('USAGE_PERIOD_BOUNDARY'); }
            try {
                $boundary = ($this->boundaries ?? app(UsageTermsBoundary::class))->freeze((string) $sub->id, (string) $grant->terms_id, $period, $now, $now + 1);
            } catch (UsageError $error) {
                if (in_array($error->errorCode, ['USAGE_TERMS_BOUNDARY', 'USAGE_PERIOD_BOUNDARY'], true)) { continue; }
                throw $error;
            }
            $user = (string) $grant->user_id; $owner = (string) $grant->subscription_id.'\0'.(string) $grant->access_subject_id;
            if (isset($owners[$user]) && $owners[$user] !== $owner) { throw new UsageError('USAGE_GRANT_MAPPING_AMBIGUOUS', 503); }
            $owners[$user] = $owner;
            // Newest finite snapshot for the same owner wins; never select between different billing owners.
            $grants[(string) $grant->user_id] = [
                'user_id' => (string) $grant->user_id, 'access_subject_id' => (string) $grant->access_subject_id,
                'auth_snapshot_id' => (string) $grant->id, 'tariff_revision_id' => (string) $grant->tariff_revision_id,
                'terms_id' => (string) $grant->terms_id, 'usage_period_id' => (string) $grant->usage_period_id,
                'issued_at' => (int) $grant->issued_at, 'lease_expires_at' => min((int) $grant->lease_expires_at, (int) $period->ends_at,
                    $boundary['terms_effective_until'] ?? PHP_INT_MAX, $sub->expires_at === null ? PHP_INT_MAX : (int) $sub->expires_at),
                'revocation_version' => (string) $grant->revocation_version,
            ];
        }
        ksort($grants, SORT_STRING);
        return ['protocol_version' => 2, 'core_instance_id' => $source->core_instance_id, 'node_id' => (string) $nodeId,
            'source_epoch' => (string) $source->source_epoch, 'receipt_generation' => (string) $source->receipt_generation,
            'retention_seconds' => (int) config('core_usage_v2.retention_seconds'),
            'max_late_report_seconds' => (int) config('core_usage_v2.max_late_report_seconds'),
            'receipt_retention_seconds' => (int) config('core_usage_v2.receipt_retention_seconds'),
            'issued_at' => $now, 'grants' => array_values($grants)];
    }

    public function advanceGeneration(int $nodeId, string $reason): object
    {
        $this->policy->requireIsolated();
        if (!preg_match('/^[A-Z][A-Z0-9_]{2,79}$/D', $reason)) { throw new UsageError('USAGE_RECOVERY_REASON_INVALID'); }
        return DB::transaction(function () use ($nodeId, $reason) {
            $source = DB::table('tx_node_usage_source')->where('node_id', $nodeId)->where('current', true)->lockForUpdate()->first();
            if (!$source || $source->core_instance_id !== config('core_usage_v2.core_instance_id')
                || UsagePayload::compare((string) config('core_usage_v2.receipt_generation'), (string) $source->receipt_generation) <= 0) {
                throw new UsageError('USAGE_RECOVERY_GENERATION_NOT_ADVANCED', 409);
            }
            // This metadata update never rewrites receipts from a previous generation.
            $history = json_decode($source->recovery_history ?? '[]', true, 32, JSON_THROW_ON_ERROR);
            $history[] = ['previous_generation' => (string) $source->receipt_generation,
                'generation' => (string) config('core_usage_v2.receipt_generation'), 'reason_code' => $reason, 'at' => time()];
            DB::table('tx_node_usage_source')->where('id', $source->id)->update([
                'receipt_generation' => config('core_usage_v2.receipt_generation'), 'recovery_history' => json_encode($history, JSON_THROW_ON_ERROR),
            ]);
            return DB::table('tx_node_usage_source')->where('id', $source->id)->first();
        });
    }

    public function identity(object $source): void
    {
        if ($source->core_instance_id !== config('core_usage_v2.core_instance_id')
            || (string) $source->receipt_generation !== (string) config('core_usage_v2.receipt_generation')) {
            throw new UsageError('CORE_INSTANCE_RECOVERY_REQUIRED', 409);
        }
    }

    public static function covers(int $start, int $end, mixed $from, mixed $until): bool
    {
        return $from !== null && $start >= (int) $from && ($until === null || $end <= (int) $until);
    }

    public static function hash(array $data): string
    {
        return hash('sha256', json_encode(self::normalize($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function normalize(mixed $data): mixed
    {
        if (!is_array($data)) { return $data; }
        if (!array_is_list($data)) { ksort($data, SORT_STRING); }
        foreach ($data as &$value) { $value = self::normalize($value); } unset($value);
        return $data;
    }
}
