<?php

namespace App\Domains\UsageV2;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Domains\UsageV2\Contracts\UsagePage;
use App\Domains\UsageV2\Contracts\UsageQueries;
use App\Domains\UsageV2\Contracts\UsageQuery;
use App\Domains\UsageV2\Contracts\UsageTargetMissing;
use Illuminate\Support\Facades\DB;

final class NativeUsageQueries implements UsageQueries
{
    public function __construct(private readonly NativeSubscriptionStore $store) {}

    public function listing(int $userId, UsageQuery $query): UsagePage
    {
        return DB::transaction(function () use ($userId, $query): UsagePage {
            $installation = app(InstallationAuthority::class)->assertNativeRead();
            [, $slot, $owned] = $this->store->lockAccount($userId, time(), $installation['installation_id']);
            $subscriptionId = $query->subscriptionId ?? $slot->subscription_id;
            if ($subscriptionId === null) {
                return new UsagePage([], null);
            }
            $subscription = $owned->firstWhere('id', $subscriptionId);
            if ($subscription === null) {
                // Throw before the transaction commits any initial empty Slot.
                throw new UsageTargetMissing();
            }
            $history = $this->store->lockHistory($subscription);
            $this->store->verifyHistory($subscription, $history);
            $periods = $history['periods']->where('id', '>', $query->afterId)->sortBy('id')->values();
            $items = [];
            foreach ($periods->take($query->limit) as $period) {
                $item = ['subscription_id' => (string) $subscriptionId, 'status' => $period->status];
                foreach (['id', 'terms_id', 'period_no', 'quota_bytes', 'upload_bytes', 'download_bytes', 'billable_bytes', 'row_version'] as $field) {
                    $item[$field] = (string) $period->$field;
                }
                foreach (['starts_at', 'ends_at', 'next_reset_at'] as $field) {
                    $item[$field] = $period->$field === null ? null : (int) $period->$field;
                }
                $item['consumed_bytes'] = NativeUsageReservations::consumed($period);
                $items[] = $item;
            }
            return new UsagePage($items,
                $periods->count() > $query->limit ? (string) $periods[$query->limit - 1]->id : null);
        }, 3);
    }
}
