<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\QueueMonitorService;
use App\Services\FailedJobSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class QueueMonitorController extends Controller
{
    public function __construct(private readonly QueueMonitorService $monitor)
    {
    }

    public function snapshot()
    {
        $status = $this->monitor->snapshot();
        try {
            $status['failed_last_7_days'] = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDays(7))
                ->count();
            $status['failed_jobs_available'] = true;
        } catch (Throwable) {
            $status['failed_last_7_days'] = null;
            $status['failed_jobs_available'] = false;
        }

        return $this->success($status)->header('Cache-Control', 'no-store');
    }

    public function failures(Request $request)
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:30',
        ]);
        $rows = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->limit((int) ($validated['limit'] ?? 10))
            ->get(['id', 'connection', 'queue', 'payload', 'exception', 'failed_at']);

        $items = $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'queue' => (string) $row->queue,
            'connection' => (string) $row->connection,
            'job' => FailedJobSummary::jobName((string) $row->payload),
            'message' => FailedJobSummary::summary((string) $row->exception, 280),
            'failed_at' => (string) $row->failed_at,
        ]);

        return $this->success($items)->header('Cache-Control', 'no-store');
    }

    public function failure(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|min:1']);
        $row = DB::table('failed_jobs')->where('id', $data['id'])->first();
        if (!$row) {
            return response()->json(['message' => '失败任务记录不存在'], 404);
        }

        return $this->success([
            'id' => (int) $row->id,
            'queue' => (string) $row->queue,
            'connection' => (string) $row->connection,
            'job' => FailedJobSummary::jobName((string) $row->payload),
            'failed_at' => (string) $row->failed_at,
            // Never return the serialized job payload. It may contain tokens or PII.
            'exception' => FailedJobSummary::exception((string) $row->exception, 12000),
        ])->header('Cache-Control', 'no-store');
    }

}
