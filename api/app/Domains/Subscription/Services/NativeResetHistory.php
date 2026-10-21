<?php

namespace App\Domains\Subscription\Services;

use App\Domains\Analytics\NativeDashboardRead;
use App\Domains\Installation\InstallationAuthority;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Reads immutable reset evidence and the retained closed period through native integrity checks. */
final class NativeResetHistory
{
    public function __construct(private readonly NativeSubscriptionStore $store) {}

    public function read(array $input, int $now): array
    {
        [$start, $end, $first, $before] = NativeDashboardRead::range($input, $now);
        return DB::transaction(function () use ($input, $start, $end, $first, $before): array {
            $installation = app(InstallationAuthority::class)->assertNativeRead()['installation_id'];
            $limit = (int) ($input['limit'] ?? 20);
            $query = DB::table('tx_subscription_operation as operation')
                ->join('tx_subscription as subscription', 'subscription.id', '=', 'operation.subscription_id')
                ->where('subscription.installation_id', $installation)->where('operation.kind', 'reset')
                ->where('operation.created_at', '>=', $first)->where('operation.created_at', '<', $before)
                ->orderByDesc('operation.id')->limit($limit + 1)->select('operation.*');
            if (isset($input['user_id'])) $query->where('operation.user_id', $input['user_id']);
            if (isset($input['before_id'])) $query->where('operation.id', '<', $input['before_id']);
            $rows = $query->get();
            $more = $rows->count() > $limit;
            $rows = $rows->take($limit);
            $results = [];
            foreach ($rows->sortBy(fn ($row) => (int) $row->subscription_id) as $row) {
                $subscription = DB::table('tx_subscription')->where('id', $row->subscription_id)->sharedLock()->first();
                if (!$subscription) throw new DomainException('NATIVE_RESET_HISTORY_DAMAGED');
                $this->store->assertOwnership($subscription, (int) $row->user_id, $installation);
                $history = [];
                foreach (['terms' => 'tx_subscription_terms', 'transitions' => 'tx_subscription_terms_transition', 'periods' => 'tx_usage_period'] as $key => $table) {
                    $history[$key] = DB::table($table)->where('subscription_id', $subscription->id)->orderBy('id')->limit(1001)->sharedLock()->get();
                    if ($history[$key]->count() > 1000) throw new DomainException('NATIVE_AGGREGATE_HISTORY_LIMIT');
                }
                $this->store->verifyHistory($subscription, $history, currentRead: true);
                $request = json_decode($row->request_snapshot, true, 64, JSON_THROW_ON_ERROR);
                $result = $this->store->replay($row, $request, $subscription, currentRead: true);
                $previous = $history['periods']->firstWhere('id', $result['previous_period_id'] ?? null);
                if (!$previous || $previous->status !== 'closed'
                    || !in_array($request['parameters']['trigger'] ?? null, ['manual', 'scheduled'], true)) {
                    throw new DomainException('NATIVE_RESET_HISTORY_DAMAGED');
                }
                $results[(string) $row->id] = [
                    'id' => (string) $row->id, 'user_id' => (string) $row->user_id,
                    'subscription_id' => (string) $row->subscription_id, 'occurred_at' => (int) $row->created_at,
                    'trigger' => $request['parameters']['trigger'], 'subscription_version' => $result['subscription_version'],
                    'previous_period_id' => (string) $previous->id, 'usage_period_id' => $result['usage_period_id'],
                    'previous_upload_bytes' => (string) $previous->upload_bytes,
                    'previous_download_bytes' => (string) $previous->download_bytes,
                    'previous_billable_bytes' => (string) $previous->billable_bytes,
                    'previous_quota_bytes' => (string) $previous->quota_bytes,
                ];
            }
            return ['time_zone' => 'UTC', 'start_date' => $start, 'end_date' => $end,
                'records' => $rows->map(fn ($row) => $results[(string) $row->id])->values()->all(),
                'next_before_id' => $more ? (string) $rows->last()->id : null];
        });
    }
}
