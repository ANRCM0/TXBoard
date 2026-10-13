<?php

namespace App\Domains\InternalReadOnly;

use App\Core\Http\TxapiResponse;
use App\Services\Analytics\AdminAnalyticsReadService;
use Illuminate\Http\Request;

final class InternalAdminDashboardDispatcher
{
    public function execute(Request $request, \stdClass $input)
    {
        return app(InternalAdminAccess::class)->run($request, $input, function (Request $request) {
            try {
                $envelope = app(AdminAnalyticsReadService::class)->getStats();
            } catch (\Throwable) {
                throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
            }
            $snapshot = is_array($envelope) ? ($envelope['data'] ?? null) : null;
            if (!is_array($snapshot)) throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
            $result = [];
            foreach (['todayIncome', 'currentMonthIncome', 'lastMonthIncome',
                'currentMonthCommissionPayout', 'lastMonthCommissionPayout'] as $key) {
                $result[$key] = (string) self::integer($snapshot, $key);
            }
            foreach (['commissionPendingTotal', 'currentMonthNewUsers', 'totalUsers', 'activeUsers',
                'onlineUsers', 'onlineDevices', 'ticketPendingTotal', 'onlineNodes'] as $key) {
                $result[$key] = self::integer($snapshot, $key);
            }
            foreach (['dayIncomeGrowth', 'monthIncomeGrowth', 'lastMonthIncomeGrowth',
                'commissionGrowth', 'userGrowth'] as $key) {
                $value = $snapshot[$key] ?? null;
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                    throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
                }
                $result[$key] = $value;
            }
            foreach (['todayTraffic', 'monthTraffic', 'totalTraffic'] as $key) {
                $traffic = $snapshot[$key] ?? null;
                if (!is_array($traffic)) throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
                foreach (['upload', 'download', 'total'] as $field) {
                    $result[$key][$field] = (string) self::integer($traffic, $field);
                }
            }
            return TxapiResponse::success($request, $result,
                ['current_only' => true, 'slice' => 'internal-admin-dashboard/1']);
        });
    }

    private static function integer(array $snapshot, string $key): int
    {
        if (!isset($snapshot[$key]) || !is_int($snapshot[$key])) {
            throw new SliceFailure('DEPENDENCY_UNAVAILABLE', 503);
        }
        return $snapshot[$key];
    }
}
