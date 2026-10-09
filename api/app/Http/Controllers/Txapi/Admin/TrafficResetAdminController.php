<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Core\Security\AdminAuditSanitizer;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\TrafficResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TrafficResetAdminController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'user_email' => ['sometimes', 'string', 'max:254'],
            'reset_type' => ['sometimes', 'in:monthly,first_day_month,yearly,first_day_year,manual,purchase'],
            'trigger_source' => ['sometimes', 'in:auto,manual,api,cron,user_access,order,gift_card'],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        $query = TrafficResetLog::query()->with('user:id,email');
        if (isset($filters['user_id'])) $query->where('user_id', (int) $filters['user_id']);
        if (!empty($filters['user_email'])) {
            $query->whereHas('user', static function ($users) use ($filters): void {
                $users->where('email', 'like', '%' . $filters['user_email'] . '%');
            });
        }
        foreach (['reset_type', 'trigger_source'] as $field) {
            if (isset($filters[$field])) $query->where($field, $filters[$field]);
        }
        if (!empty($filters['start_date'])) {
            $query->where('reset_time', '>=', $filters['start_date'] . ' 00:00:00');
        }
        if (!empty($filters['end_date'])) {
            $query->where('reset_time', '<=', $filters['end_date'] . ' 23:59:59');
        }
        $page = $query->orderByDesc('reset_time')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page',
                (int) ($filters['page'] ?? 1));

        return TxapiResponse::success($request,
            $page->getCollection()->map(static fn (TrafficResetLog $log) => self::dto($log))->all(),
            ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
             'total' => $page->total(), 'last_page' => $page->lastPage()]);
    }

    public function stats(Request $request): JsonResponse
    {
        $filters = $request->validate(['days' => ['sometimes', 'integer', 'min:1', 'max:365']]);
        $since = now()->subDays((int) ($filters['days'] ?? 30))->startOfDay();
        $query = TrafficResetLog::query()->where('reset_time', '>=', $since);
        // Clone each query to keep the predicates independent.
        return TxapiResponse::success($request, [
            'total_resets' => (clone $query)->count(),
            'auto_resets' => (clone $query)->where('trigger_source', 'auto')->count(),
            'manual_resets' => (clone $query)->where('trigger_source', 'manual')->count(),
            'cron_resets' => (clone $query)->where('trigger_source', 'cron')->count(),
            'order_resets' => (clone $query)->where('trigger_source', 'order')->count(),
            'gift_card_resets' => (clone $query)->where('trigger_source', 'gift_card')->count(),
        ]);
    }

    public function show(Request $request, TrafficResetService $service): JsonResponse
    {
        $filters = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        $user = User::query()->findOrFail((int) $request->route('id'));
        $history = $service->getUserResetHistory($user, (int) ($filters['limit'] ?? 10));
        return TxapiResponse::success($request, [
            'user' => [
                'id' => (int) $user->id,
                'email' => (string) $user->email,
                'reset_count' => (int) ($user->reset_count ?? 0),
                'last_reset_at' => $user->last_reset_at === null ? null : (int) $user->last_reset_at,
                'next_reset_at' => $user->next_reset_at === null ? null : (int) $user->next_reset_at,
            ],
            'history' => $history->map(static fn (TrafficResetLog $log) => self::dto($log))->all(),
        ]);
    }

    public function reset(Request $request, TrafficResetService $service): JsonResponse
    {
        $input = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:255']]);
        $user = User::query()->findOrFail((int) $request->route('id'));
        if (!$service->canReset($user)) {
            return TxapiResponse::error($request, 'TRAFFIC_RESET_NOT_ALLOWED',
                'This user cannot reset traffic', 409);
        }
        $metadata = ['admin_id' => (int) $request->user()->id];
        if (isset($input['reason']) && trim($input['reason']) !== '') {
            $metadata['reason'] = trim($input['reason']);
        }
        if (!$service->manualReset($user, $metadata)) {
            return TxapiResponse::error($request, 'TRAFFIC_RESET_FAILED',
                'Traffic reset failed or eligibility changed', 409);
        }
        $user->refresh();
        return TxapiResponse::success($request, [
            'user_id' => (int) $user->id,
            'email' => (string) $user->email,
            'reset_time' => now()->toIso8601String(),
            'next_reset_at' => $user->next_reset_at === null ? null : (int) $user->next_reset_at,
        ]);
    }

    private static function dto(TrafficResetLog $log): array
    {
        $old = static fn (int $upload, int $download, int $total) => [
            'upload' => $upload, 'download' => $download,
            'total' => $total, 'formatted' => $log->formatTraffic($total),
        ];
        return [
            'id' => (int) $log->id,
            'user_id' => (int) $log->user_id,
            'user_email' => $log->user?->email ?? 'N/A',
            'reset_type' => (string) $log->reset_type,
            'reset_type_name' => $log->getResetTypeName(),
            'reset_time' => $log->reset_time?->toIso8601String(),
            'old_traffic' => $old((int) $log->old_upload, (int) $log->old_download, (int) $log->old_total),
            'new_traffic' => $old((int) $log->new_upload, (int) $log->new_download, (int) $log->new_total),
            'trigger_source' => (string) $log->trigger_source,
            'trigger_source_name' => $log->getSourceName(),
            'metadata' => AdminAuditSanitizer::redact($log->metadata ?? []),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
