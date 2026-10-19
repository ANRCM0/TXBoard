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
    public function __construct(private readonly NativeSubscriptionStore $store,
        private readonly \App\Domains\Network\Contracts\NodeIdentities $nodes) {}

    public function nodeTrafficHistory(array $nodeIds, int $days, int $now): array
    {
        app(InstallationAuthority::class)->assertNativeRead();
        if (!in_array($days, [7, 14, 30], true) || count($nodeIds) < 1 || count($nodeIds) > 20
            || count(array_unique($nodeIds, SORT_STRING)) !== count($nodeIds)) {
            throw new \InvalidArgumentException('USAGE_HISTORY_INPUT_INVALID');
        }
        $startsAt = intdiv($now, 86400) * 86400 - ($days - 1) * 86400;
        return DB::transaction(function () use ($nodeIds, $days, $now, $startsAt): array {
            $nodes = array_column($this->nodes->findMany($nodeIds), null, 'id');
            if (count($nodes) !== count($nodeIds)) throw new UsageTargetMissing();
            $totals = [];
            foreach ($nodeIds as $id) {
                $totals[$id] = [];
                for ($day = 0; $day < $days; $day++) $totals[$id][gmdate('Y-m-d', $startsAt + $day * 86400)] = '0';
            }
            // Do not SUM database numeric columns: SQLite can coerce large values to floats.
            // Fetch one extra entry to reject an oversized request without returning partial totals.
            $entries = DB::table('tx_usage_entry as entry')
                ->join('tx_usage_batch as batch', 'batch.id', '=', 'entry.batch_id')
                ->where('batch.status', 'settled')->whereIn('batch.node_id', $nodeIds)
                ->where('batch.ended_at', '>', $startsAt)->where('batch.ended_at', '<=', $now)
                ->orderBy('entry.id')->limit(100001)
                ->select(['batch.node_id', 'batch.ended_at', 'entry.raw_up_bytes', 'entry.raw_down_bytes'])->cursor();
            $count = 0;
            foreach ($entries as $entry) {
                if (++$count > 100000) throw new \App\Domains\UsageV2\Contracts\UsageHistoryUnavailable('USAGE_HISTORY_ENTRY_LIMIT');
                $date = gmdate('Y-m-d', (int) $entry->ended_at - 1);
                $id = (string) $entry->node_id;
                foreach (['raw_up_bytes', 'raw_down_bytes'] as $field) {
                    $bytes = (string) $entry->$field;
                    if (!preg_match('/\A(?:0|[1-9][0-9]{0,39})\z/D', $bytes)) {
                        throw new \App\Domains\UsageV2\Contracts\UsageHistoryUnavailable('USAGE_HISTORY_BYTES_INVALID');
                    }
                    $totals[$id][$date] = bcadd($totals[$id][$date], $bytes, 0);
                    if (strlen($totals[$id][$date]) > 40) throw new \App\Domains\UsageV2\Contracts\UsageHistoryUnavailable('USAGE_HISTORY_BYTES_OVERFLOW');
                }
            }
            $items = [];
            foreach ($nodeIds as $id) {
                $points = [];
                foreach ($totals[$id] as $date => $total) $points[] = ['date' => $date, 'total_bytes' => $total];
                $items[] = ['id' => (string) $id, 'name' => $nodes[$id]['name'], 'points' => $points];
            }
            return ['time_zone' => 'UTC', 'basis' => 'settled-raw-usage/v1', 'bucket' => 'day',
                'starts_at' => $startsAt, 'ends_at' => $now, 'nodes' => $items];
        });
    }

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
