<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Domains\UsageV2\NativeUsageReservations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class NativeUsageController
{
    public function index(Request $request, NativeSubscriptionStore $store): JsonResponse
    {
        if (array_diff(array_keys($request->all()), ['subscription_id', 'after_id', 'limit'])) {
            return TxapiResponse::error($request, 'NATIVE_USAGE_INPUT_INVALID', 'Unsupported usage fields', 422);
        }
        $input = $request->validate(['subscription_id' => ['sometimes', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'after_id' => ['sometimes', 'integer', 'min:0', 'max:'.PHP_INT_MAX],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $result = DB::transaction(function () use ($input, $store): array {
            $installation = app(InstallationAuthority::class)->assertNativeRead();
            $userId = (int) Auth::guard('sanctum')->id();
            [, $slot, $owned] = $store->lockAccount($userId, time(), $installation['installation_id']);
            $subscriptionId = $input['subscription_id'] ?? $slot->subscription_id;
            if ($subscriptionId === null) {
                return [[], null];
            }
            $subscription = $owned->firstWhere('id', $subscriptionId);
            if ($subscription === null) { abort(404); }
            $history = $store->lockHistory($subscription);
            $store->verifyHistory($subscription, $history);
            $limit = (int) ($input['limit'] ?? 100);
            $periods = $history['periods']->where('id', '>', (int) ($input['after_id'] ?? 0))->sortBy('id')->values();
            $items = [];
            foreach ($periods->take($limit) as $period) {
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
            return [$items, $periods->count() > $limit ? (string) $periods[$limit - 1]->id : null];
        }, 3);
        return TxapiResponse::success($request, $result[0],
            ['schema' => 'native-usage-periods/v1', 'next_after_id' => $result[1]])->header('Cache-Control', 'private, no-store');
    }
}
