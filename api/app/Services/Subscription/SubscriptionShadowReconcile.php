<?php

namespace App\Services\Subscription;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Read-only, per-aggregate comparison. Reports field names, never credentials or values. */
final class SubscriptionShadowReconcile
{
    public function __construct(private LegacySubscriptionSnapshot $snapshots)
    {
    }

    public function run(string $runKey, int $limit = 100, int $afterId = 0): array
    {
        if ($limit < 1 || $limit > 1000 || $afterId < 0) {
            throw new InvalidArgumentException('INVALID_RECONCILE_BOUNDS');
        }

        return DB::transaction(function () use ($runKey, $limit, $afterId) {
            $checkpoint = DB::table('tx_migration_checkpoint')->where('run_key', $runKey)->first();
            if (!$checkpoint) {
                throw new RuntimeException('MIGRATION_RUN_MISSING');
            }
            $context = json_decode($checkpoint->details, true, 512, JSON_THROW_ON_ERROR);
            $highWater = (int) $checkpoint->high_water_user_id;
            if ($afterId > $highWater) {
                throw new InvalidArgumentException('CURSOR_OUTSIDE_HIGH_WATER');
            }
            // Include orphan maps, so a deleted legacy user cannot disappear from the report.
            $users = DB::table('tx_user')->select('id')->where('id', '>', $afterId)->where('id', '<=', $highWater);
            $maps = DB::table('tx_legacy_subscription_map')->select('legacy_user_id as id')
                ->where('legacy_user_id', '>', $afterId)->where('legacy_user_id', '<=', $highWater);
            $exceptions = DB::table('tx_legacy_subscription_exception')->select('legacy_user_id as id')
                ->where('legacy_user_id', '>', $afterId)->where('legacy_user_id', '<=', $highWater);
            $ids = DB::query()->fromSub($users->union($maps)->union($exceptions), 'sources')->orderBy('id')->limit($limit + 1)->pluck('id')->all();
            $hasMore = count($ids) > $limit;
            $ids = array_slice($ids, 0, $limit);
            $differences = [];
            if ($checkpoint->phase !== 'backfilled') {
                $differences[] = ['legacy_user_id' => null, 'code' => 'BACKFILL_INCOMPLETE', 'fields' => ['phase']];
            }
            if (User::query()->where('id', '>', $highWater)->exists()) {
                $differences[] = ['legacy_user_id' => null, 'code' => 'HIGH_WATER_CHANGED', 'fields' => ['high_water_user_id']];
            }
            foreach ($ids as $id) {
                $fields = [];
                $code = 'SHADOW_MISMATCH';
                try {
                    $user = User::query()->sharedLock()->find($id);
                    $map = DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $id)->first();
                    $exception = DB::table('tx_legacy_subscription_exception')->where('legacy_user_id', $id)->where('state', 'unresolved')->first();
                    if (!$user || !$map || $exception) {
                        $code = !$user ? 'LEGACY_USER_MISSING' : ($exception?->error_code ?? 'MAPPING_MISSING');
                        $fields = ['mapping'];
                    } else {
                        $source = $this->snapshots->capture($user, $context);
                        $saved = json_decode($map->source_snapshot, true, 512, JSON_THROW_ON_ERROR);
                        if (!hash_equals($map->source_hash, $this->snapshots->hash($source)) || $map->state !== 'mapped') {
                            $code = 'LEGACY_SOURCE_CHANGED';
                            foreach ($source as $key => $value) {
                                if (!hash_equals($this->snapshots->hash([$saved[$key] ?? null]), $this->snapshots->hash([$value]))) {
                                    $fields[] = $key;
                                }
                            }
                            $fields[] = 'source_hash';
                        }
                        if (!hash_equals($map->source_hash, $this->snapshots->hash($saved))) {
                            $fields[] = 'source_snapshot';
                        }
                        $this->compareProjection($map, $saved, $fields);
                    }
                } catch (Throwable $error) {
                    $code = $error instanceof RuntimeException && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $error->getMessage())
                        ? $error->getMessage() : 'RECONCILE_FAILED';
                    $fields[] = 'source';
                }
                if ($fields) {
                    $differences[] = ['legacy_user_id' => (string) $id, 'code' => $code, 'fields' => array_values(array_unique($fields))];
                }
            }
            $lifecycle = [];
            if (config('legacy_lifecycle_reconcile.enabled') !== false) {
                $lifecycle = app(LegacyLifecycleShadowReconcile::class)->page($ids, $context);
                foreach ($lifecycle['issues'] as $issue) {
                    $differences[] = $issue + ['fields' => ['lifecycle']];
                }
            }
            $next = $ids ? (int) end($ids) : $afterId;

            return ['run_key' => $runKey, 'authority' => 'legacy', 'passed' => !$differences,
                'checked_count' => (string) count($ids), 'after_id' => (string) $afterId,
                'next_cursor' => (string) $next, 'complete' => !$hasMore, 'differences' => $differences]
                + ($lifecycle ? ['lifecycle' => $lifecycle] : []);
        });
    }

    private function compareProjection(object $map, array $source, array &$fields): void
    {
        $subscription = DB::table('tx_subscription')->where('id', $map->subscription_id)->first();
        $terms = DB::table('tx_subscription_terms')->where('id', $map->terms_id)->first();
        $period = DB::table('tx_usage_period')->where('id', $map->usage_period_id)->first();
        $subject = DB::table('tx_access_subject')->where('id', $map->access_subject_id)->first();
        $slot = DB::table('tx_subscription_slot')->where('user_id', $map->legacy_user_id)->where('slot_no', 1)->first();
        $expectedStatus = $this->snapshots->status($source, (int) $map->created_at);
        $this->compare($subscription, ['user_id' => $source['id'], 'plan_id' => $source['plan_id'],
            'status' => $expectedStatus, 'current_terms_id' => (string) $map->terms_id,
            'starts_at' => (string) $map->created_at, 'expires_at' => $source['expired_at']], 'subscription', $fields);
        $this->compare($terms, ['subscription_id' => (string) $map->subscription_id, 'version_no' => '1',
            'plan_revision_id' => null, 'quota_bytes' => $source['transfer_enable'], 'group_id' => $source['group_id'],
            'speed_limit_mbps' => $source['speed_limit'], 'device_limit' => $source['device_limit'],
            'billing_timezone' => $source['context']['billing_timezone'], 'content_hash' => $map->source_hash,
            'effective_from' => (string) $map->created_at, 'effective_until' => null], 'terms', $fields);
        if ($terms && (!hash_equals($this->snapshots->hash(json_decode($terms->legacy_snapshot, true, 512, JSON_THROW_ON_ERROR)), $this->snapshots->hash($source))
            || !hash_equals($this->snapshots->hash(json_decode($terms->reset_policy, true, 512, JSON_THROW_ON_ERROR)), $this->snapshots->hash($source['reset_policy'])))) {
            $fields[] = 'terms.snapshot';
        }
        $this->compare($period, ['subscription_id' => (string) $map->subscription_id, 'terms_id' => (string) $map->terms_id,
            'period_no' => '1', 'starts_at' => $source['last_reset_at'], 'ends_at' => $source['next_reset_at'],
            'quota_bytes' => $source['transfer_enable'], 'upload_bytes' => $source['u'], 'download_bytes' => $source['d'],
            'billable_bytes' => (string) ((int) $source['u'] + (int) $source['d']),
            'boundary_kind' => $source['reset_policy']['boundary'], 'last_reset_at' => $source['last_reset_at'],
            'next_reset_at' => $source['next_reset_at'], 'legacy_reset_count' => $source['reset_count'],
            'status' => 'shadow'], 'usage_period', $fields);
        $this->compare($subject, ['subscription_id' => (string) $map->subscription_id, 'provider_id' => 'legacy-shadow',
            'provider_subject_ref' => 'user:'.$map->legacy_user_id, 'status' => 'pending',
            'auth_version' => '0', 'revocation_fact_version' => '0'], 'access_subject', $fields);
        $expectedSlot = in_array($expectedStatus, ['active', 'suspended'], true) ? (string) $map->subscription_id : null;
        if (($slot?->subscription_id === null ? null : (string) $slot->subscription_id) !== $expectedSlot
            || DB::table('tx_subscription_slot')->where('user_id', $map->legacy_user_id)->whereNotNull('subscription_id')->count() > 1) {
            $fields[] = 'slot';
        }
    }

    private function compare(?object $actual, array $expected, string $prefix, array &$fields): void
    {
        if (!$actual) {
            $fields[] = $prefix;
            return;
        }
        foreach ($expected as $name => $value) {
            if (($actual->$name === null ? null : (string) $actual->$name) !== $value) {
                $fields[] = $prefix.'.'.$name;
            }
        }
    }
}
