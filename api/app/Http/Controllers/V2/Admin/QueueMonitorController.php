<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\QueueMonitorService;
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
            'job' => $this->jobName((string) $row->payload),
            'message' => $this->safeExcerpt((string) $row->exception, 280),
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
            'job' => $this->jobName((string) $row->payload),
            'failed_at' => (string) $row->failed_at,
            // Never return the serialized job payload. It may contain tokens or PII.
            'exception' => $this->redact((string) $row->exception, 12000),
        ])->header('Cache-Control', 'no-store');
    }

    private function jobName(string $payload): string
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return '未知任务';
        }
        $name = (string) ($data['displayName'] ?? $data['job'] ?? '未知任务');
        // Only a job class/name is displayed, never serialized command data.
        $name = preg_replace('/[^A-Za-z0-9_\\\\.:-]/', '', $name) ?: '未知任务';
        return substr($name, 0, 120);
    }

    private function safeExcerpt(string $exception, int $maxLength): string
    {
        return $this->redact(strtok($exception, "\n") ?: $exception, $maxLength);
    }

    private function redact(string $value, int $maxLength): string
    {
        $value = preg_replace('/(Bearer\s+)[^\s]+/i', '$1[REDACTED]', $value);
        $value = preg_replace('/((?:password|passwd|secret|api[_-]?key|authorization|access[_-]?token|token)\s*[:=]\s*)[^\s&,"\047]+/i', '$1[REDACTED]', $value);
        $value = preg_replace('/(https?:\/\/)[^@\s\/]+@/i', '$1[REDACTED]@', $value);
        return mb_substr($value ?? '', 0, $maxLength);
    }
}
