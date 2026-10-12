<?php

namespace App\Services\Subscription;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Explicit internal shadow DTO; existing public responses continue to read legacy User. */
final class SubscriptionShadowReader
{
    public function forLegacyUser(int $userId): ?array
    {
        return DB::transaction(function () use ($userId) {
            $map = DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $userId)->first();
            if (!$map) {
                return null;
            }
            if ($map->state !== 'mapped') {
                throw new RuntimeException('SHADOW_SOURCE_UNVERIFIED');
            }
            $subscription = DB::table('tx_subscription')->where('id', $map->subscription_id)->first();
            $terms = DB::table('tx_subscription_terms')->where('id', $map->terms_id)->first();
            $period = DB::table('tx_usage_period')->where('id', $map->usage_period_id)->first();
            if (!$subscription || !$terms || !$period) {
                throw new RuntimeException('SHADOW_PROJECTION_MISSING');
            }
            $up = (int) $period->upload_bytes;
            $down = (int) $period->download_bytes;
            if ($up < 0 || $down < 0 || $up > PHP_INT_MAX - $down) {
                throw new RuntimeException('SHADOW_TRAFFIC_OVERFLOW');
            }

            return ['authority' => 'legacy', 'subscription_id' => (string) $subscription->id,
                'terms_id' => (string) $terms->id, 'usage_period_id' => (string) $period->id,
                'access_subject_id' => (string) $map->access_subject_id, 'plan_id' => $subscription->plan_id === null ? null : (string) $subscription->plan_id,
                'status' => $subscription->status, 'expires_at' => $subscription->expires_at === null ? null : (int) $subscription->expires_at,
                'quota_bytes' => $period->quota_bytes === null ? null : (string) $period->quota_bytes,
                'upload_bytes' => (string) $period->upload_bytes, 'download_bytes' => (string) $period->download_bytes,
                'remaining_bytes' => $period->quota_bytes === null ? null : (string) max(0, (int) $period->quota_bytes - ($up + $down)),
                'boundary_kind' => $period->boundary_kind, 'source_verified_at' => $map->last_verified_at];
        });
    }
}
