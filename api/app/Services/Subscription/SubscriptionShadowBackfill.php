<?php

namespace App\Services\Subscription;

use App\Models\AccessSubject;
use App\Models\Subscription;
use App\Models\SubscriptionTerms;
use App\Models\UsagePeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Additive, bounded migration on an explicitly isolated copy; legacy stays authoritative. */
final class SubscriptionShadowBackfill
{
    public function __construct(private LegacySubscriptionSnapshot $snapshots, private SubscriptionSlotService $slots)
    {
    }

    public function run(string $runKey, int $limit, array $context, ?int $retryUser = null): array
    {
        if (!config('core_shadow.isolated_copy') || !app()->environment('local', 'testing')) {
            throw new RuntimeException('ISOLATED_COPY_REQUIRED');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $runKey) || $limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('INVALID_BACKFILL_BOUNDS');
        }
        $context = $this->snapshots->context($context['billing_timezone'], $context['currency']);

        return DB::transaction(function () use ($runKey, $limit, $context, $retryUser) {
            $now = time();
            // insertOrIgnore + UNIQUE(run_key) is the durable run initializer.
            DB::table('tx_migration_checkpoint')->insertOrIgnore([
                'run_key' => $runKey, 'phase' => 'backfilling', 'cursor_user_id' => 0,
                'high_water_user_id' => User::query()->max('id') ?? 0,
                'processed_count' => 0, 'row_version' => 1,
                'details' => json_encode($context, JSON_THROW_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $checkpoint = DB::table('tx_migration_checkpoint')->where('run_key', $runKey)->lockForUpdate()->first();
            if (!hash_equals($this->snapshots->hash(json_decode($checkpoint->details, true, 512, JSON_THROW_ON_ERROR)), $this->snapshots->hash($context))) {
                throw new RuntimeException('MIGRATION_CONTEXT_CHANGED');
            }
            if ($retryUser !== null) {
                if ($retryUser < 1 || $retryUser > $checkpoint->high_water_user_id) {
                    throw new InvalidArgumentException('RETRY_OUTSIDE_HIGH_WATER');
                }
                $ids = [$retryUser];
            } else {
                $ids = User::query()->where('id', '>', $checkpoint->cursor_user_id)
                    ->where('id', '<=', $checkpoint->high_water_user_id)->orderBy('id')->limit($limit)->pluck('id')->all();
            }
            $results = [];
            foreach ($ids as $id) {
                try {
                    // Savepoint rolls back the entire aggregate on a failed slot or FK.
                    $results[] = DB::transaction(fn () => $this->map((int) $id, $runKey, $context, $now));
                    DB::table('tx_legacy_subscription_exception')->where('legacy_user_id', $id)
                        ->update(['state' => 'resolved', 'updated_at' => $now]);
                } catch (Throwable $error) {
                    // Exception text can contain SQL bindings; persist only an allowlisted code.
                    $code = $error instanceof RuntimeException && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $error->getMessage())
                        ? $error->getMessage() : 'MAPPING_TRANSACTION_FAILED';
                    $previous = DB::table('tx_legacy_subscription_exception')->where('legacy_user_id', $id)->first();
                    DB::table('tx_legacy_subscription_exception')->updateOrInsert(['legacy_user_id' => $id], [
                        'run_key' => $runKey, 'state' => 'unresolved', 'source_hash' => null,
                        'error_code' => $code, 'attempts' => ($previous?->attempts ?? 0) + 1,
                        'created_at' => $previous?->created_at ?? $now, 'updated_at' => $now,
                    ]);
                    $results[] = ['legacy_user_id' => (string) $id, 'status' => 'exception', 'error_code' => $code];
                }
            }
            $cursor = $retryUser === null && $ids ? (int) end($ids) : (int) $checkpoint->cursor_user_id;
            $hasMore = User::query()->where('id', '>', $cursor)->where('id', '<=', $checkpoint->high_water_user_id)->exists();
            $processed = (int) $checkpoint->processed_count + ($retryUser === null ? count($ids) : 0);
            $phase = $hasMore ? 'backfilling' : 'backfilled';
            DB::table('tx_migration_checkpoint')->where('id', $checkpoint->id)->update([
                'cursor_user_id' => $cursor, 'processed_count' => $processed, 'phase' => $phase,
                'row_version' => $checkpoint->row_version + 1, 'updated_at' => $now,
            ]);

            return ['run_key' => $runKey, 'phase' => $phase, 'cursor_user_id' => (string) $cursor,
                'high_water_user_id' => (string) $checkpoint->high_water_user_id,
                'processed_count' => (string) $processed, 'results' => $results];
        }, 3);
    }

    private function map(int $id, string $runKey, array $context, int $now): array
    {
        $user = User::query()->lockForUpdate()->find($id);
        if (!$user) {
            throw new RuntimeException('LEGACY_USER_MISSING');
        }
        $source = $this->snapshots->capture($user, $context);
        $hash = $this->snapshots->hash($source);
        $existing = DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $id)->lockForUpdate()->first();
        if ($existing) {
            $changed = !hash_equals($existing->source_hash, $hash);
            DB::table('tx_legacy_subscription_map')->where('id', $existing->id)->update([
                'state' => $changed ? 'source_changed' : 'mapped',
                'error_code' => $changed ? 'LEGACY_SOURCE_CHANGED' : null,
                'row_version' => $existing->row_version + 1, 'updated_at' => $now,
            ]);

            return ['legacy_user_id' => (string) $id, 'status' => $changed ? 'source_changed' : 'unchanged'];
        }

        $status = $this->snapshots->status($source, $now);
        $subscription = Subscription::create([
            'user_id' => $id, 'plan_id' => $source['plan_id'], 'status' => $status,
            'starts_at' => $now, 'expires_at' => $source['expired_at'], 'ended_at' => null,
            'suspended_reason' => $status === 'suspended' ? 'legacy_user_banned' : null,
            'row_version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $terms = SubscriptionTerms::create([
            'subscription_id' => $subscription->id, 'version_no' => 1, 'plan_revision_id' => null,
            'effective_from' => $now, 'effective_until' => null,
            'quota_bytes' => $source['transfer_enable'], 'group_id' => $source['group_id'],
            'speed_limit_mbps' => $source['speed_limit'], 'device_limit' => $source['device_limit'],
            'reset_policy' => $source['reset_policy'], 'billing_timezone' => $context['billing_timezone'],
            'legacy_snapshot' => $source, 'content_hash' => $hash, 'created_at' => $now,
        ]);
        $subscription->update(['current_terms_id' => $terms->id]);
        $period = UsagePeriod::create([
            'subscription_id' => $subscription->id, 'terms_id' => $terms->id, 'period_no' => 1,
            'starts_at' => $source['last_reset_at'], 'ends_at' => $source['next_reset_at'],
            'quota_bytes' => $source['transfer_enable'], 'upload_bytes' => $source['u'],
            'download_bytes' => $source['d'], 'billable_bytes' => (int) $source['u'] + (int) $source['d'],
            'status' => 'shadow', 'boundary_kind' => $source['reset_policy']['boundary'],
            'last_reset_at' => $source['last_reset_at'], 'next_reset_at' => $source['next_reset_at'],
            'legacy_reset_count' => $source['reset_count'], 'row_version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $subject = AccessSubject::create([
            'subscription_id' => $subscription->id, 'provider_id' => 'legacy-shadow',
            'provider_subject_ref' => 'user:'.$id, 'status' => 'pending', 'auth_version' => 0,
            'revocation_fact_version' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        if (in_array($status, ['active', 'suspended'], true)) {
            $this->slots->reserve($id, $subscription->id, $now);
        }
        DB::table('tx_legacy_subscription_map')->insert([
            'legacy_user_id' => $id, 'subscription_id' => $subscription->id,
            'terms_id' => $terms->id, 'usage_period_id' => $period->id, 'access_subject_id' => $subject->id,
            'source_hash' => $hash, 'source_snapshot' => json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'migration_key' => LegacySubscriptionSnapshot::VERSION.'/user/'.$id, 'run_key' => $runKey,
            'state' => 'mapped', 'error_code' => null, 'row_version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return ['legacy_user_id' => (string) $id, 'status' => 'mapped'];
    }
}
