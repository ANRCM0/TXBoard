<?php

namespace App\Services;

use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\WaitTimeCalculator;
use Throwable;

class QueueMonitorService
{
    public function __construct(
        private readonly MasterSupervisorRepository $masters,
        private readonly SupervisorRepository $supervisors,
        private readonly JobRepository $jobs,
        private readonly MetricsRepository $metrics,
        private readonly WaitTimeCalculator $waitTimes,
    ) {
    }

    /**
     * Only Horizon's live supervisor heartbeats and its own counters are used.
     * Absence of a heartbeat is not interpreted as a healthy, idle queue.
     */
    public function snapshot(): array
    {
        $connection = (string) config('queue.default', 'sync');
        $empty = [
            'status' => 'unavailable',
            'connection' => $connection,
            'processes' => null,
            'recent_jobs' => null,
            'jobs_per_minute' => null,
            'wait_seconds' => null,
            'longest_wait_queue' => null,
            'pending_jobs' => null,
            'observed_at' => now()->toIso8601String(),
        ];

        if ($connection === 'sync' || $connection === 'null') {
            return array_merge($empty, [
                'status' => 'not_applicable',
                'message' => '当前使用同步任务模式，没有独立的 Horizon 队列进程。',
            ]);
        }

        try {
            $masters = $this->masters->all();
            $supervisors = $this->supervisors->all();
            $status = !count($masters)
                ? 'inactive'
                : (collect($masters)->every(fn ($master) => $master->status === 'paused') ? 'paused' : 'running');

            $processes = collect($supervisors)
                ->sum(fn ($supervisor) => array_sum((array) ($supervisor->processes ?? [])));
            $waiting = $this->waitTimes->calculate();
            $longest = count($waiting) ? array_key_first($waiting) : null;

            return array_merge($empty, [
                'status' => $status,
                'processes' => (int) $processes,
                'recent_jobs' => (int) $this->jobs->countRecent(),
                'pending_jobs' => (int) $this->jobs->countPending(),
                'jobs_per_minute' => (int) $this->metrics->jobsProcessedPerMinute(),
                'wait_seconds' => $longest !== null ? (int) max(0, $waiting[$longest]) : null,
                'longest_wait_queue' => $longest === null ? null : (explode(':', $longest, 2)[1] ?? $longest),
                'message' => $status === 'inactive' ? '未检测到 Horizon 主进程心跳，请检查队列服务。' : null,
            ]);
        } catch (Throwable) {
            // Do not leak Redis credentials, hostnames or stack traces via monitoring.
            return array_merge($empty, [
                'status' => 'unavailable',
                'message' => '无法连接 Horizon 指标服务，请检查 Redis 或 Horizon 状态。',
            ]);
        }
    }
}
