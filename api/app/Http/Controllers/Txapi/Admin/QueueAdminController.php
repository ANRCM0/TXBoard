<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Services\FailedJobSummary;
use App\Services\QueueMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only native Horizon visibility behind the rotating admin path.
 * No queue mutation, retry, raw command payload or broad worker access.
 */
final class QueueAdminController
{
    public function snapshot(Request $request, QueueMonitorService $monitor): JsonResponse
    {
        $status = $monitor->snapshot();
        try {
            $status['failed_last_7_days'] = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDays(7))->count();
            $status['failed_jobs_available'] = true;
        } catch (Throwable) {
            $status['failed_last_7_days'] = null;
            $status['failed_jobs_available'] = false;
        }

        return TxapiResponse::success($request, $status)->header('Cache-Control', 'no-store');
    }

    public function failures(Request $request): JsonResponse
    {
        $params = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);
        $rows = DB::table('failed_jobs')
            ->orderByDesc('failed_at')->orderByDesc('id')
            ->limit((int) ($params['limit'] ?? 10))
            ->get(['id', 'connection', 'queue', 'payload', 'exception', 'failed_at']);

        $items = $rows->map(static fn ($row): array => [
            'id' => (int) $row->id,
            'connection' => (string) $row->connection,
            'queue' => (string) $row->queue,
            'job' => FailedJobSummary::jobName((string) $row->payload),
            'message' => FailedJobSummary::summary((string) $row->exception),
            'failed_at' => (string) $row->failed_at,
        ])->all();

        return TxapiResponse::success($request, $items)->header('Cache-Control', 'no-store');
    }

    public function failure(Request $request): JsonResponse
    {
        $row = DB::table('failed_jobs')->where('id', (int) $request->route('id'))->first();
        if (!$row) {
            return TxapiResponse::error($request, 'QUEUE_FAILURE_NOT_FOUND',
                'Failed job not found', 404)->header('Cache-Control', 'no-store');
        }

        return TxapiResponse::success($request, [
            'id' => (int) $row->id,
            'connection' => (string) $row->connection,
            'queue' => (string) $row->queue,
            'job' => FailedJobSummary::jobName((string) $row->payload),
            'failed_at' => (string) $row->failed_at,
            'exception' => FailedJobSummary::exception((string) $row->exception),
        ])->header('Cache-Control', 'no-store');
    }
}
