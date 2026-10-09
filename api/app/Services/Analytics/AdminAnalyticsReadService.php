<?php

namespace App\Services\Analytics;

use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Server;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Native administrative analytics read model.
 *
 * Aggregation formulas are intentionally retained from the historical
 * dashboard during the control-plane migration. Callers must validate
 * bounded windows before invoking dated queries.
 */
final class AdminAnalyticsReadService
{
    public function getOrder(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'type' => 'nullable|in:paid_total,paid_count,commission_total,commission_count',
        ]);

        $query = Stat::where('record_type', 'd');

        // Apply date filters
        if ($request->input('start_date')) {
            $query->where('record_at', '>=', strtotime($request->input('start_date')));
        }
        if ($request->input('end_date')) {
            $query->where('record_at', '<=', strtotime($request->input('end_date') . ' 23:59:59'));
        }

        $statistics = $query->orderBy('record_at', 'DESC')
            ->get();

        $summary = [
            'paid_total' => 0,
            'paid_count' => 0,
            'commission_total' => 0,
            'commission_count' => 0,
            'start_date' => $request->input('start_date', date('Y-m-d', $statistics->last()?->record_at)),
            'end_date' => $request->input('end_date', date('Y-m-d', $statistics->first()?->record_at)),
            'avg_paid_amount' => 0,
            'avg_commission_amount' => 0
        ];

        $dailyStats = [];
        foreach ($statistics as $statistic) {
            $date = date('Y-m-d', $statistic['record_at']);

            // Update summary
            $summary['paid_total'] += $statistic['paid_total'];
            $summary['paid_count'] += $statistic['paid_count'];
            $summary['commission_total'] += $statistic['commission_total'];
            $summary['commission_count'] += $statistic['commission_count'];

            // Calculate daily stats
            $dailyData = [
                'date' => $date,
                'paid_total' => $statistic['paid_total'],
                'paid_count' => $statistic['paid_count'],
                'commission_total' => $statistic['commission_total'],
                'commission_count' => $statistic['commission_count'],
                'avg_order_amount' => $statistic['paid_count'] > 0 ? round($statistic['paid_total'] / $statistic['paid_count'], 2) : 0,
                'avg_commission_amount' => $statistic['commission_count'] > 0 ? round($statistic['commission_total'] / $statistic['commission_count'], 2) : 0
            ];

            if ($request->input('type')) {
                $dailyStats[] = [
                    'date' => $date,
                    'value' => $statistic[$request->input('type')],
                    'type' => $this->getTypeLabel($request->input('type'))
                ];
            } else {
                $dailyStats[] = $dailyData;
            }
        }

        // Calculate averages for summary
        if ($summary['paid_count'] > 0) {
            $summary['avg_paid_amount'] = round($summary['paid_total'] / $summary['paid_count'], 2);
        }
        if ($summary['commission_count'] > 0) {
            $summary['avg_commission_amount'] = round($summary['commission_total'] / $summary['commission_count'], 2);
        }

        // Add percentage calculations to summary
        $summary['commission_rate'] = $summary['paid_total'] > 0
            ? round(($summary['commission_total'] / $summary['paid_total']) * 100, 2)
            : 0;

        return [
            'code' => 0,
            'message' => 'success',
            'data' => [
                'list' => array_reverse($dailyStats),
                'summary' => $summary,
            ]
        ];
    }


    private function getTypeLabel(string $type): string
    {
        return match ($type) {
            'paid_total' => '收款金额',
            'paid_count' => '收款笔数',
            'commission_total' => '佣金金额(已发放)',
            'commission_count' => '佣金笔数(已发放)',
            default => $type
        };
    }


    public function getStats()
    {
        return app('cache')->remember('txapi.admin.analytics.dashboard.v2', 15, function () {
        $now = time();
        $currentMonthStart = strtotime(date('Y-m-01'));
        $lastMonthStart = strtotime('-1 month', $currentMonthStart);
        $twoMonthsAgoStart = strtotime('-2 month', $currentMonthStart);

        // Today's start timestamp
        $todayStart = strtotime('today');
        $yesterdayStart = strtotime('-1 day', $todayStart);

        $onlineNodes = Server::all()->filter(function ($server) {
            return !!$server->is_online;
        })->count();

        $orderStats = Order::query()
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? AND status NOT IN (0, 2) THEN total_amount ELSE 0 END), 0) AS today_income,
                 COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? AND status NOT IN (0, 2) THEN total_amount ELSE 0 END), 0) AS yesterday_income,
                 COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? AND status NOT IN (0, 2) THEN total_amount ELSE 0 END), 0) AS current_month_income,
                 COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? AND status NOT IN (0, 2) THEN total_amount ELSE 0 END), 0) AS last_month_income,
                 COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? AND status NOT IN (0, 2) THEN total_amount ELSE 0 END), 0) AS two_months_ago_income,
                 COALESCE(SUM(CASE WHEN commission_status = 0 AND invite_user_id IS NOT NULL AND status = ? AND commission_balance > 0 THEN 1 ELSE 0 END), 0) AS commission_pending_total',
                [
                    $todayStart, $now,
                    $yesterdayStart, $todayStart,
                    $currentMonthStart, $now,
                    $lastMonthStart, $currentMonthStart,
                    $twoMonthsAgoStart, $lastMonthStart,
                    Order::STATUS_COMPLETED,
                ]
            )
            ->first();

        $commissionStats = CommissionLog::query()
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN get_amount ELSE 0 END), 0) AS current_month_payout,
                 COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN get_amount ELSE 0 END), 0) AS last_month_payout,
                 COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN get_amount ELSE 0 END), 0) AS two_months_ago_payout',
                [
                    $currentMonthStart, $now,
                    $lastMonthStart, $currentMonthStart,
                    $twoMonthsAgoStart, $lastMonthStart,
                ]
            )
            ->first();

        $onlineCutoff = $now - 600;
        $userStats = User::query()
            ->selectRaw(
                'COUNT(*) AS total_users,
                 COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) AS current_month_new_users,
                 COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END), 0) AS last_month_new_users,
                 COALESCE(SUM(CASE WHEN expired_at >= ? OR expired_at IS NULL THEN 1 ELSE 0 END), 0) AS active_users,
                 COALESCE(SUM(CASE WHEN t >= ? THEN 1 ELSE 0 END), 0) AS online_users,
                 COALESCE(SUM(CASE WHEN t >= ? THEN COALESCE(online_count, 0) ELSE 0 END), 0) AS online_devices',
                [
                    $currentMonthStart, $now,
                    $lastMonthStart, $currentMonthStart,
                    $now,
                    $onlineCutoff,
                    $onlineCutoff,
                ]
            )
            ->first();

        $trafficStats = StatServer::query()
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN record_at >= ? AND record_at < ? THEN u ELSE 0 END), 0) AS today_upload,
                 COALESCE(SUM(CASE WHEN record_at >= ? AND record_at < ? THEN d ELSE 0 END), 0) AS today_download,
                 COALESCE(SUM(CASE WHEN record_at >= ? AND record_at < ? THEN u + d ELSE 0 END), 0) AS today_total,
                 COALESCE(SUM(CASE WHEN record_at >= ? AND record_at < ? THEN u ELSE 0 END), 0) AS month_upload,
                 COALESCE(SUM(CASE WHEN record_at >= ? AND record_at < ? THEN d ELSE 0 END), 0) AS month_download,
                 COALESCE(SUM(CASE WHEN record_at >= ? AND record_at < ? THEN u + d ELSE 0 END), 0) AS month_total,
                 COALESCE(SUM(u), 0) AS total_upload,
                 COALESCE(SUM(d), 0) AS total_download,
                 COALESCE(SUM(u + d), 0) AS total',
                [
                    $todayStart, $now,
                    $todayStart, $now,
                    $todayStart, $now,
                    $currentMonthStart, $now,
                    $currentMonthStart, $now,
                    $currentMonthStart, $now,
                ]
            )
            ->first();

        $todayIncome = (int) $orderStats->today_income;
        $yesterdayIncome = (int) $orderStats->yesterday_income;
        $currentMonthIncome = (int) $orderStats->current_month_income;
        $lastMonthIncome = (int) $orderStats->last_month_income;
        $twoMonthsAgoIncome = (int) $orderStats->two_months_ago_income;
        $commissionPendingTotal = (int) $orderStats->commission_pending_total;
        $currentMonthCommissionPayout = (int) $commissionStats->current_month_payout;
        $lastMonthCommissionPayout = (int) $commissionStats->last_month_payout;
        $twoMonthsAgoCommission = (int) $commissionStats->two_months_ago_payout;
        $currentMonthNewUsers = (int) $userStats->current_month_new_users;
        $lastMonthNewUsers = (int) $userStats->last_month_new_users;
        $totalUsers = (int) $userStats->total_users;
        $activeUsers = (int) $userStats->active_users;
        $onlineUsers = (int) $userStats->online_users;
        $onlineDevices = (int) $userStats->online_devices;
        $todayTraffic = (object) [
            'upload' => (int) $trafficStats->today_upload,
            'download' => (int) $trafficStats->today_download,
            'total' => (int) $trafficStats->today_total,
        ];
        $monthTraffic = (object) [
            'upload' => (int) $trafficStats->month_upload,
            'download' => (int) $trafficStats->month_download,
            'total' => (int) $trafficStats->month_total,
        ];
        $totalTraffic = (object) [
            'upload' => (int) $trafficStats->total_upload,
            'download' => (int) $trafficStats->total_download,
            'total' => (int) $trafficStats->total,
        ];

        // Calculate growth rates
        $monthIncomeGrowth = $lastMonthIncome > 0 ? round(($currentMonthIncome - $lastMonthIncome) / $lastMonthIncome * 100, 1) : 0;
        $lastMonthIncomeGrowth = $twoMonthsAgoIncome > 0 ? round(($lastMonthIncome - $twoMonthsAgoIncome) / $twoMonthsAgoIncome * 100, 1) : 0;
        $commissionGrowth = $twoMonthsAgoCommission > 0 ? round(($lastMonthCommissionPayout - $twoMonthsAgoCommission) / $twoMonthsAgoCommission * 100, 1) : 0;
        $userGrowth = $lastMonthNewUsers > 0 ? round(($currentMonthNewUsers - $lastMonthNewUsers) / $lastMonthNewUsers * 100, 1) : 0;
        $dayIncomeGrowth = $yesterdayIncome > 0 ? round(($todayIncome - $yesterdayIncome) / $yesterdayIncome * 100, 1) : 0;

        $ticketPendingTotal = Ticket::where('status', 0)->count();

        return [
            'data' => [
                // 收入相关
                'todayIncome' => $todayIncome,
                'dayIncomeGrowth' => $dayIncomeGrowth,
                'currentMonthIncome' => $currentMonthIncome,
                'lastMonthIncome' => $lastMonthIncome,
                'monthIncomeGrowth' => $monthIncomeGrowth,
                'lastMonthIncomeGrowth' => $lastMonthIncomeGrowth,

                // 佣金相关
                'currentMonthCommissionPayout' => $currentMonthCommissionPayout,
                'lastMonthCommissionPayout' => $lastMonthCommissionPayout,
                'commissionGrowth' => $commissionGrowth,
                'commissionPendingTotal' => $commissionPendingTotal,

                // 用户相关
                'currentMonthNewUsers' => $currentMonthNewUsers,
                'totalUsers' => $totalUsers,
                'activeUsers' => $activeUsers,
                'userGrowth' => $userGrowth,
                'onlineUsers' => $onlineUsers,
                'onlineDevices' => $onlineDevices,

                // 工单相关
                'ticketPendingTotal' => $ticketPendingTotal,

                // 节点相关
                'onlineNodes' => $onlineNodes,

                // 流量统计
                'todayTraffic' => [
                    'upload' => $todayTraffic->upload ?? 0,
                    'download' => $todayTraffic->download ?? 0,
                    'total' => $todayTraffic->total ?? 0
                ],
                'monthTraffic' => [
                    'upload' => $monthTraffic->upload ?? 0,
                    'download' => $monthTraffic->download ?? 0,
                    'total' => $monthTraffic->total ?? 0
                ],
                'totalTraffic' => [
                    'upload' => $totalTraffic->upload ?? 0,
                    'download' => $totalTraffic->download ?? 0,
                    'total' => $totalTraffic->total ?? 0
                ]
            ]
        ];
        });
    }


    public function getTrafficRank(Request $request)
    {
        $request->validate([
            'type' => 'required|in:node,user',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999'
        ]);

        $type = $request->input('type');
        $startDate = $request->input('start_time', strtotime('-7 days'));
        $endDate = $request->input('end_time', time());
        $cacheKey = 'txapi.admin.analytics.traffic-rank.' . sha1(
            $type . ':' . intdiv((int) $startDate, 15) . ':' . intdiv((int) $endDate, 15)
        );

        return app('cache')->remember($cacheKey, 15, function () use ($type, $startDate, $endDate) {
        $previousStartDate = $startDate - ($endDate - $startDate);
        $previousEndDate = $startDate;

        if ($type === 'node') {
            // Get node traffic data
            $currentData = StatServer::selectRaw('server_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $startDate)
                ->where('record_at', '<=', $endDate)
                ->groupBy('server_id')
                ->orderBy('value', 'DESC')
                ->limit(10)
                ->get();

            // Get previous period data for comparison
            $previousData = StatServer::selectRaw('server_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->whereIn('server_id', $currentData->pluck('id'))
                ->groupBy('server_id')
                ->get()
                ->keyBy('id');

        } else {
            // Get user traffic data
            $currentData = StatUser::selectRaw('user_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $startDate)
                ->where('record_at', '<=', $endDate)
                ->groupBy('user_id')
                ->orderBy('value', 'DESC')
                ->limit(10)
                ->get();

            // Get previous period data for comparison
            $previousData = StatUser::selectRaw('user_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->whereIn('user_id', $currentData->pluck('id'))
                ->groupBy('user_id')
                ->get()
                ->keyBy('id');
        }

        $result = [];
        $ids = $currentData->pluck('id');
        $names = $type === 'node'
            ? Server::whereIn('id', $ids)->pluck('name', 'id')
            : User::whereIn('id', $ids)->pluck('email', 'id');

        foreach ($currentData as $data) {
            $previousValue = isset($previousData[$data->id]) ? $previousData[$data->id]->value : 0;
            $change = $previousValue > 0 ? round(($data->value - $previousValue) / $previousValue * 100, 1) : 0;

            $result[] = [
                'id' => (string) $data->id,
                'name' => $names[$data->id] ?? ($type === 'node' ? "Node {$data->id}" : "User {$data->id}"),
                'value' => $data->value,
                'previousValue' => $previousValue,
                'change' => $change,
                'timestamp' => date('c', $endDate)
            ];
        }

        return [
            'timestamp' => date('c'),
            'data' => $result
        ];
        });
    }
}
