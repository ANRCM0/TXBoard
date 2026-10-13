<?php

namespace App\Services\Subscription;

use Illuminate\Support\Facades\DB;
use Throwable;

/** Bounded immutable lifecycle associations; never a migration gate. */
final class LegacyLifecycleShadowReconcile
{
    private const CREATIONS = ['admin.user.create' => 'admin-creation-shadow/v1',
        'user.register' => 'registration-creation-shadow/v1', 'install.admin.create' => 'bootstrap-creation-shadow/v1'];

    public function run(string $runKey, int $limit = 100, int $afterId = 0): array
    {
        $this->require(DB::transactionLevel() === 0, 'LIFECYCLE_OUTER_TRANSACTION_UNSUPPORTED');
        $this->require($limit >= 1 && $limit <= 1000 && $afterId >= 0 && $afterId <= 2147483647
            && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,63}\z/D', $runKey), 'LIFECYCLE_BOUNDS_INVALID');
        return $this->safe(function () use ($runKey, $limit, $afterId): array {
            return DB::transaction(function () use ($runKey, $limit, $afterId): array {
                $captureContext = $this->context();
                $checkpoint = DB::table('tx_migration_checkpoint')->where('run_key', $runKey)->sharedLock()->first(['phase','high_water_user_id',DB::raw('substr(details,1,2049) as details')]);
                $this->require($checkpoint !== null, 'LIFECYCLE_RUN_MISSING');
                $this->require(is_string($checkpoint->details) && strlen($checkpoint->details) <= 2048, 'LIFECYCLE_MAPPING_CONTEXT_INVALID');
                $mappingContext = json_decode($checkpoint->details, true, 16, JSON_THROW_ON_ERROR);
                $this->mappingContext($mappingContext, $captureContext);
                $highWater = $this->id($checkpoint->high_water_user_id, true);
                $this->require($afterId <= $highWater, 'LIFECYCLE_CURSOR_OUTSIDE_HIGH_WATER');
                $ids = $this->ids()->where('id', '>', $afterId)->where('id', '<=', $highWater)
                    ->orderBy('id')->limit($limit + 1)->pluck('id')->all();
                $more = count($ids) > $limit; $ids = array_slice($ids, 0, $limit);
                $page = $this->page($ids, $mappingContext); $issues = [];
                if ($checkpoint->phase !== 'backfilled') $issues[] = 'BACKFILL_INCOMPLETE';
                if ($this->ids()->where('id', '>', $highWater)->exists()) $issues[] = 'HIGH_WATER_CHANGED';
                return $page + ['run_key' => $runKey, 'authority' => 'legacy', 'high_water_user_id' => (string) $highWater,
                    'next_cursor' => (string) ($ids ? end($ids) : $afterId), 'complete' => !$more,
                    'page_consistent' => $page['issues'] === [] && $issues === [], 'range_issues' => $issues];
            });
        });
    }

    /** Caller owns a bounded transaction and preserves existing source/projection checks. */
    public function page(array $ids, array $mappingContext): array
    {
        $this->require(DB::transactionLevel() > 0 && array_is_list($ids) && count($ids) <= 1000, 'LIFECYCLE_TRANSACTION_OR_BOUNDS_INVALID');
        return $this->safe(function () use ($ids, $mappingContext): array {
            $previous = 0;
            foreach ($ids as $rawId) {
                $id = $this->id($rawId);
                $this->require($id > $previous, 'LIFECYCLE_IDS_NOT_ORDERED_UNIQUE'); $previous = $id;
            }
            $context = $this->context(); $this->mappingContext($mappingContext, $context); $items = []; $issues = [];
            foreach ($ids as $rawId) {
                $id = $this->id($rawId); $itemIssues = [];
                $exists = DB::table('tx_user')->where('id', $id)->sharedLock()->first(['id']) !== null;
                $current = $exists ? app(LegacyUserMutationCapture::class)->initialEvidenceSource($id) : null;
                $creation = $this->anchor($id, $context, false); $deletion = $this->anchor($id, $context, true);
                $first = app(LegacyUserMutationCapture::class)->initialMutationBeforeHash($id);
                $classification = $exists ? ($deletion ? 'source_reappeared' : 'source_present')
                    : ($deletion ? 'known_admin_deletion' : 'source_missing_unknown');
                if ($creation && $current && $creation['initial_hash'] !== $this->hash($current))
                    $itemIssues[] = 'LIFECYCLE_INITIAL_SOURCE_CHANGED';
                if ($creation && $first !== null && $creation['initial_hash'] !== $first)
                    $itemIssues[] = 'LIFECYCLE_INITIAL_MUTATION_MISMATCH';
                if (!$exists) $itemIssues[] = 'LEGACY_USER_MISSING';
                if ($exists && $deletion) $itemIssues[] = 'LIFECYCLE_SOURCE_REAPPEARED';
                if ($creation && $deletion && (int) $creation['captured_at'] > (int) $deletion['captured_at'])
                    $itemIssues[] = 'LIFECYCLE_TIME_ORDER_INVALID';
                $map = DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $id)->sharedLock()
                    ->first(['subscription_id','terms_id','usage_period_id','access_subject_id','state']);
                if ($map) {
                    foreach (['subscription_id','terms_id','usage_period_id','access_subject_id'] as $key)
                        $this->mappingId($map->$key);
                }
                $subscription = $map ? DB::table('tx_subscription')->where('id', $map->subscription_id)->sharedLock()
                    ->first(['id','user_id','status']) : null;
                $slot = DB::table('tx_subscription_slot')->where('user_id', $id)->where('slot_no', 1)->sharedLock()->first(['subscription_id']);
                if ($map) $this->require(in_array($map->state, ['mapped','source_changed','exception'], true), 'LIFECYCLE_MAPPING_INVALID');
                if ($subscription) {
                    $this->require($this->mappingId($subscription->id) === $this->mappingId($map->subscription_id), 'LIFECYCLE_MAPPING_INVALID');
                    $this->mappingId($subscription->user_id);
                }
                if ($slot?->subscription_id !== null) {
                    $slotId = $this->mappingId($slot->subscription_id);
                    if (!$map || $slotId !== $this->mappingId($map->subscription_id)) $itemIssues[] = 'LIFECYCLE_MAPPING_INVALID';
                }
                if ($subscription) $this->require(in_array($subscription->status, ['pending','active','suspended','expired','revoked'], true), 'LIFECYCLE_MAPPING_INVALID');
                if (!$map) $itemIssues[] = 'MAPPING_MISSING';
                elseif (!$subscription || (string) $subscription->user_id !== (string) $id || $map->state !== 'mapped')
                    $itemIssues[] = 'LIFECYCLE_MAPPING_INVALID';
                if (DB::table('tx_legacy_subscription_exception')->where('legacy_user_id', $id)->where('state','unresolved')->sharedLock()->first(['legacy_user_id']) !== null)
                    $itemIssues[] = 'LIFECYCLE_UNRESOLVED_EXCEPTION';
                $items[] = ['legacy_user_id' => (string) $id, 'classification' => $classification,
                    'creation_evidence' => $creation ? ['source_kind' => $creation['source_kind'], 'content_hash' => $creation['content_hash']] : null,
                    'deletion_evidence' => $deletion ? ['source_kind' => $deletion['source_kind'], 'content_hash' => $deletion['content_hash']] : null,
                    'current_matches_initial' => $creation && $current ? $creation['initial_hash'] === $this->hash($current) : null,
                    'first_mutation_before_matches_initial' => $creation && $first !== null ? $creation['initial_hash'] === $first : null,
                    'mapping' => $map ? ['subscription_id' => (string) $map->subscription_id, 'terms_id' => (string) $map->terms_id,
                        'usage_period_id' => (string) $map->usage_period_id, 'access_subject_id' => (string) $map->access_subject_id,
                        'state' => $map->state, 'subscription_status' => $subscription?->status,
                        'slot_occupied' => $slot?->subscription_id !== null] : null,
                    'issues' => $itemIssues];
                foreach ($itemIssues as $code) $issues[] = ['legacy_user_id' => (string) $id, 'code' => $code];
            }
            return ['read_only' => true, 'items' => $items, 'issues' => $issues, 'mutation_chain_verified' => false,
                'global_coverage_complete' => false, 'projection_applied' => false, 'projection_verified' => false, 'migration_gate_open' => false];
        });
    }

    private function ids(): \Illuminate\Database\Query\Builder
    {
        $union = DB::table('tx_user')->select('id');
        foreach (['tx_legacy_subscription_map','tx_legacy_subscription_exception','tx_legacy_user_creation_shadow',
            'tx_legacy_user_deletion_shadow','tx_legacy_capture_shadow_stream'] as $table)
            $union->union(DB::table($table)->select('legacy_user_id as id'));
        return DB::query()->fromSub($union, 'lifecycle_ids');
    }

    private function anchor(int $id, array $context, bool $deleted): ?array
    {
        $snapshotKey = $deleted ? 'admitted_snapshot' : 'initial_snapshot'; $hashKey = $deleted ? 'admitted_hash' : 'initial_hash';
        $columns = ['legacy_user_id','source_kind','source_version','actor_id','context_hash',$hashKey,'captured_at','content_hash',
            DB::raw('substr(context,1,2049) as context'), DB::raw('substr('.$snapshotKey.',1,8193) as '.$snapshotKey)];
        if ($deleted) $columns[] = 'deletion_confirmed';
        $row = DB::table($deleted ? 'tx_legacy_user_deletion_shadow' : 'tx_legacy_user_creation_shadow')
            ->where('legacy_user_id', $id)->sharedLock()->first($columns);
        if (!$row) return null;
        $this->require(is_string($row->context) && strlen($row->context) <= 2048
            && is_string($row->$snapshotKey) && strlen($row->$snapshotKey) <= 8192, 'LIFECYCLE_EVIDENCE_INVALID');
        $payload = (array) $row; unset($payload['content_hash']);
        foreach (['legacy_user_id','actor_id','captured_at'] as $key) $payload[$key] = (string) $payload[$key];
        $this->require($this->id($payload['legacy_user_id']) === $id, 'LIFECYCLE_EVIDENCE_INVALID');
        $this->id($payload['actor_id']);
        $this->require(preg_match('/\A[1-9][0-9]{0,18}\z/D', $payload['captured_at'])
            && (strlen($payload['captured_at']) < 19 || strcmp($payload['captured_at'], '9223372036854775807') <= 0), 'LIFECYCLE_EVIDENCE_INVALID');
        $payload['context'] = json_decode($row->context, true, 16, JSON_THROW_ON_ERROR);
        $payload[$snapshotKey] = json_decode($row->$snapshotKey, true, 16, JSON_THROW_ON_ERROR);
        app(LegacyUserMutationCapture::class)->initialEvidenceSnapshot($payload[$snapshotKey]);
        $this->require($payload[$snapshotKey]['id'] === (string) $id && $this->json($payload['context']) === $this->json($context)
            && $payload['context_hash'] === $this->hash($context) && $payload[$hashKey] === $this->hash($payload[$snapshotKey]), 'LIFECYCLE_EVIDENCE_INVALID');
        if ($deleted) {
            $this->require(in_array($payload['deletion_confirmed'], [true,1,'1'], true)
                && $payload['source_kind'] === 'admin.user.delete' && $payload['source_version'] === LegacyAdminDeletionShadow::VERSION,
                'LIFECYCLE_EVIDENCE_INVALID');
            $payload['deletion_confirmed'] = true;
        } else {
            $this->require(isset(self::CREATIONS[$payload['source_kind']]) && self::CREATIONS[$payload['source_kind']] === $payload['source_version']
                && (!in_array($payload['source_kind'], ['user.register','install.admin.create'], true) || $payload['actor_id'] === (string) $id),
                'LIFECYCLE_EVIDENCE_INVALID');
        }
        $this->require($row->content_hash === $this->hash($payload), 'LIFECYCLE_EVIDENCE_INVALID');
        return $payload + ['content_hash' => $row->content_hash];
    }

    private function mappingContext(array $mapping, array $capture): void
    {
        $this->require(count($mapping) === 3 && !array_diff(array_keys($mapping), ['mapping_version','billing_timezone','currency'])
            && $mapping['mapping_version'] === LegacySubscriptionSnapshot::VERSION
            && $mapping['billing_timezone'] === $capture['billing_timezone'] && $mapping['currency'] === $capture['currency'],
            'LIFECYCLE_MAPPING_CONTEXT_INVALID');
    }

    private function mappingId(mixed $value): string
    {
        $this->require(is_int($value) || is_string($value), 'LIFECYCLE_MAPPING_INVALID');
        $value = (string) $value;
        $this->require(preg_match('/\A[1-9][0-9]{0,18}\z/D', $value)
            && (strlen($value) < 19 || strcmp($value, '9223372036854775807') <= 0), 'LIFECYCLE_MAPPING_INVALID');
        return $value;
    }

    private function context(): array
    {
        $this->require(config('legacy_lifecycle_reconcile.enabled') === true, 'LIFECYCLE_ISOLATED_REQUIRED');
        return app(LegacyUserMutationCapture::class)->initialEvidenceContext();
    }
    private function id(mixed $value, bool $zero = false): int
    {
        $this->require(is_int($value) || is_string($value), 'LIFECYCLE_ID_INVALID');
        $value = (string) $value;
        $this->require(preg_match($zero ? '/\A(?:0|[1-9][0-9]{0,9})\z/D' : '/\A[1-9][0-9]{0,9}\z/D', $value)
            && (int) $value <= 2147483647, 'LIFECYCLE_ID_INVALID');
        return (int) $value;
    }
    private function json(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            return array_map($normalize, $item);
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    private function hash(array $value): string { return hash('sha256', $this->json($value)); }
    private function require(bool $ok, string $code): void { if (!$ok) throw new LegacyCaptureShadowException($code); }
    private function safe(callable $callback): mixed
    {
        try { return $callback(); }
        catch (LegacyCaptureShadowException $error) { throw $error; }
        catch (Throwable) { throw new LegacyCaptureShadowException('LIFECYCLE_STORAGE_OR_EVIDENCE_FAILED'); }
    }
}
