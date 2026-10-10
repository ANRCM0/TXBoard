<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\Server;
use App\Models\Stat;
use App\Models\StatUser;
use App\Services\Analytics\AdminAnalyticsReadService;
use App\Services\StatisticalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AnalyticsAdminController
{
    private const MAX_DAYS = 366;

    public function __construct(
        private readonly AdminAnalyticsReadService $reports,
        private readonly StatisticalService $statistics
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        return $this->respond($request, $this->reports->getStats()['data']);
    }

    public function overview(Request $request): JsonResponse
    {
        $snapshot = $this->reports->getStats()['data'];
        // Reuse the exact same cached dashboard snapshot for summary cards.
        // Avoid a second full scan of Server/User/Order tables.
        return $this->respond($request, [
            'month_income' => $snapshot['currentMonthIncome'],
            'month_register_total' => $snapshot['currentMonthNewUsers'],
            'ticket_pending_total' => $snapshot['ticketPendingTotal'],
            'commission_pending_total' => $snapshot['commissionPendingTotal'],
            'day_income' => $snapshot['todayIncome'],
            'last_month_income' => $snapshot['lastMonthIncome'],
            'commission_month_payout' => $snapshot['currentMonthCommissionPayout'],
            'commission_last_month_payout' => $snapshot['lastMonthCommissionPayout'],
            'online_nodes' => $snapshot['onlineNodes'],
            'online_devices' => $snapshot['onlineDevices'],
            'online_users' => $snapshot['onlineUsers'],
            'today_traffic' => $snapshot['todayTraffic'],
            'month_traffic' => $snapshot['monthTraffic'],
            'total_traffic' => $snapshot['totalTraffic'],
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d'],
            'type' => ['sometimes', 'in:paid_total,paid_count,commission_total,commission_count'],
        ]);
        [$start, $end] = $this->calendarRange($data);
        $request->merge(['start_date' => date('Y-m-d', $start), 'end_date' => date('Y-m-d', $end)]);
        return $this->respond($request, $this->reports->getOrder($request)['data']);
    }

    public function trafficRank(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:node,user'],
            'start_time' => ['sometimes', 'integer', 'min:1000000000', 'max:9999999999'],
            'end_time' => ['sometimes', 'integer', 'min:1000000000', 'max:9999999999'],
        ]);
        [$start, $end] = $this->timestampRange($data, 7);
        $request->merge(['start_time' => $start, 'end_time' => $end]);
        $value = $this->reports->getTrafficRank($request);
        return $this->respond($request, $value['data'] ?? []);
    }

    public function ranking(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:server_traffic_rank,user_consumption_rank,invite_rank'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'start_time' => ['sometimes', 'integer', 'min:1000000000', 'max:9999999999'],
            'end_time' => ['sometimes', 'integer', 'min:1000000000', 'max:9999999999'],
        ]);
        [$start, $end] = $this->timestampRange($data, 30);
        $this->statistics->setStartAt($start);
        $this->statistics->setEndAt($end);
        return $this->respond($request,
            $this->statistics->getRanking($data['type'], (int) ($data['limit'] ?? 20)) ?? []);
    }

    public function userTraffic(Request $request): JsonResponse
    {
        $id = (int) $request->route('id');
        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = StatUser::query()->where('user_id', $id)->orderByDesc('record_at')
            ->orderByDesc('id')->paginate((int) ($params['per_page'] ?? 20),
                ['id', 'user_id', 'server_rate', 'record_type', 'record_at', 'u', 'd'],
                'page', (int) ($params['page'] ?? 1));
        return TxapiResponse::success($request, $page->items(), [
            'page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage(),
        ])->header('Cache-Control', 'no-store');
    }

    public function records(Request $request): JsonResponse
    {
        $params = $request->validate([
            'type' => ['required', 'in:paid_total,commission_total,register_count'],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        [$start, $end] = $this->calendarRange($params);
        $page = Stat::query()->where('record_type', 'd')
            ->where('record_at', '>=', $start)
            ->where('record_at', '<', $end + 86400)
            ->orderBy('record_at')->orderBy('id')
            ->paginate((int) ($params['per_page'] ?? 100), [
                'id', 'record_at', 'record_type',
                'paid_total', 'paid_count', 'commission_total', 'commission_count',
                'register_count',
            ], 'page', (int) ($params['page'] ?? 1));
        // Return only the requested daily metric and timestamp.
        $items = collect($page->items())->map(static fn (Stat $row): array => [
            'record_at' => $row->record_at,
            'value' => $row->getAttribute($params['type']),
        ])->all();
        return TxapiResponse::success($request, $items, [
            'page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage(),
        ])->header('Cache-Control', 'no-store');
    }

    public function serverRank(Request $request): JsonResponse
    {
        $period = (string) $request->route('period');
        if (!in_array($period, ['today', 'yesterday'], true)) {
            return TxapiResponse::error($request, 'ANALYTICS_PERIOD_INVALID',
                'Unknown ranking period', 422);
        }
        // For admin screens these use the existing domain ranking service and
        // an explicit daily window, not an unbounded all-time scan.
        return $this->respond($request, $this->statistics->getServerRank($period));
    }

    private function calendarRange(array $data): array
    {
        $today = strtotime('today');
        $start = strtotime($data['start_date'] ?? date('Y-m-d', strtotime('-29 days', $today)));
        $end = strtotime($data['end_date'] ?? date('Y-m-d', $today));
        if ($start === false || $end === false || $start > $end ||
            $end > $today || ($end - $start) > (self::MAX_DAYS - 1) * 86400) {
            throw ValidationException::withMessages([
                'date_range' => 'Select a valid date range of up to 366 days ending today or earlier',
            ]);
        }
        return [$start, $end];
    }

    private function timestampRange(array $data, int $defaultDays): array
    {
        $end = (int) ($data['end_time'] ?? time());
        $start = (int) ($data['start_time'] ?? ($end - $defaultDays * 86400));
        if ($start > $end || $end > time() + 60 ||
            $end - $start > self::MAX_DAYS * 86400) {
            throw ValidationException::withMessages([
                'date_range' => 'Select a valid range of up to 366 days',
            ]);
        }
        return [$start, $end];
    }

    private function respond(Request $request, mixed $data): JsonResponse
    {
        return TxapiResponse::success($request, $data)
            ->header('Cache-Control', 'private, no-store');
    }
}
