<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

class TrafficHealth extends Command
{
    protected $signature = 'traffic:health
        {--minutes=15 : Recent settlement observation window}
        {--threshold=1000 : Warning threshold for pending traffic jobs}
        {--json : Print machine-readable metrics}';

    protected $description = 'Inspect settlement throughput and Redis traffic backlog; alert on queue failure';

    public function handle(): int
    {
        $minutes = filter_var($this->option('minutes'), FILTER_VALIDATE_INT);
        $threshold = filter_var($this->option('threshold'), FILTER_VALIDATE_INT);
        if ($minutes === false || $minutes < 1 || $minutes > 10080
            || $threshold === false || $threshold < 1) {
            $this->error('Minutes must be 1..10080, threshold must be a positive integer.');
            return self::FAILURE;
        }

        $cutoff = time() - ($minutes * 60);
        $recent = DB::table(\App\Support\Database\NativeTableName::runtime('v2_traffic_batch'))->where('created_at', '>=', $cutoff);
        $settled = (clone $recent)->count();
        $nodes = (clone $recent)->distinct()->count('server_id');
        $lastSettledAt = DB::table(\App\Support\Database\NativeTableName::runtime('v2_traffic_batch'))->max('created_at');
        $queuePending = null;
        $alerts = [];

        try {
            $queuePending = (int) Queue::connection('redis')->size('traffic_fetch');
        } catch (\Throwable $exception) {
            $alerts[] = 'traffic_queue_unavailable';
            Log::error('Traffic queue health inspection failed', [
                'error' => $exception->getMessage(),
            ]);
        }

        if ($queuePending !== null && $queuePending >= $threshold) {
            $alerts[] = 'traffic_queue_backlog_high';
        }

        $metrics = [
            'window_minutes' => (int) $minutes,
            'settled_batches' => $settled,
            'active_servers' => $nodes,
            'last_settled_at' => $lastSettledAt === null ? null : (int) $lastSettledAt,
            'pending_traffic_jobs' => $queuePending,
            'alerts' => $alerts,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($metrics, JSON_THROW_ON_ERROR));
        } else {
            $this->info("Settled batches: {$settled} across {$nodes} nodes ({$minutes}m).");
            $this->line('Pending traffic jobs: ' . ($queuePending === null ? 'unavailable' : $queuePending));
        }

        if ($alerts !== []) {
            Log::warning('TXBoard traffic reliability alarm', $metrics);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
