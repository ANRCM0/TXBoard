<?php

namespace App\Services\AgentOps;

use App\Models\AgentAuditLog;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use App\Utils\CacheKey;
use App\WebSocket\NodeWorker;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\WaitTimeCalculator;

class AgentOpsService
{
    public function systemStatus(): array
    {
        $masters = app(MasterSupervisorRepository::class)->all();
        $horizon = !empty($masters)
            && !collect($masters)->contains(fn ($master) => ($master->status ?? null) === 'paused');

        $scheduleLast = (int) Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), 0);
        $wsHeartbeat = (int) Cache::get(NodeWorker::HEARTBEAT_CACHE_KEY, 0);

        return [
            'schedule' => $scheduleLast > 0 && (time() - $scheduleLast) < 120,
            'horizon' => $horizon,
            'websocket_server' => $wsHeartbeat > 0 && (time() - $wsHeartbeat) < 30,
            'schedule_last_runtime' => $scheduleLast ?: null,
            'websocket_last_heartbeat' => $wsHeartbeat ?: null,
            'timestamp' => time(),
        ];
    }

    public function machines(?array $allowedMachineIds = null): array
    {
        $query = ServerMachine::withCount('servers')->orderBy('id');
        if ($allowedMachineIds !== null) {
            $query->whereIn('id', $allowedMachineIds);
        }

        return $query->get()
            ->map(fn (ServerMachine $machine) => [
                'id' => $machine->id,
                'name' => $machine->name,
                'is_active' => $machine->is_active,
                'last_seen_at' => $machine->last_seen_at,
                'load_status' => $machine->load_status,
                'servers_count' => $machine->servers_count,
            ])
            ->values()
            ->toArray();
    }

    public function nodes(?array $allowedNodeIds = null): array
    {
        $nodes = ServerService::getAllServers();
        if ($allowedNodeIds !== null) {
            $allowed = array_fill_keys(array_map('intval', $allowedNodeIds), true);
            $nodes = $nodes->filter(fn (Server $node) => isset($allowed[(int) $node->id]));
        }

        return $nodes
            ->map(fn (Server $node) => $this->nodeSnapshot($node))
            ->values()
            ->toArray();
    }

    public function nodeMetrics(int $nodeId): array
    {
        $node = ServerService::getServer($nodeId);
        if (!$node) {
            throw new \InvalidArgumentException('Node not found');
        }
        $node->append(['last_check_at', 'last_push_at', 'online', 'is_online', 'available_status', 'load_status', 'metrics', 'online_conn']);
        return $this->nodeSnapshot($node);
    }

    public function diagnoseNode(int $nodeId): array
    {
        $node = ServerService::getServer($nodeId);
        if (!$node) {
            throw new \InvalidArgumentException('Node not found');
        }
        $node->append(['last_check_at', 'last_push_at', 'online', 'is_online', 'available_status', 'load_status', 'metrics', 'online_conn']);

        $snapshot = $this->nodeSnapshot($node);
        $warnings = [];
        $load = $snapshot['load_status'] ?? [];
        $metrics = $snapshot['metrics'] ?? [];

        if (!$snapshot['websocket']) {
            $warnings[] = ['code' => 'websocket_offline', 'severity' => 'critical'];
        }
        if (array_key_exists('kernel_status', $metrics) && !$metrics['kernel_status']) {
            $warnings[] = ['code' => 'kernel_not_running', 'severity' => 'critical'];
        }

        $cpu = (float) ($load['cpu'] ?? 0);
        if ($cpu >= 90) {
            $warnings[] = ['code' => 'high_cpu', 'severity' => 'warning', 'value' => $cpu];
        }

        foreach (['mem' => 'high_memory', 'disk' => 'high_disk'] as $key => $code) {
            $total = (float) ($load[$key]['total'] ?? 0);
            $used = (float) ($load[$key]['used'] ?? 0);
            $pct = $total > 0 ? ($used / $total) * 100 : 0;
            if ($pct >= 90) {
                $warnings[] = ['code' => $code, 'severity' => 'warning', 'value' => round($pct, 2)];
            }
        }

        $updatedAt = (int) ($metrics['updated_at'] ?? $load['updated_at'] ?? 0);
        if ($updatedAt > 0 && time() - $updatedAt > 300) {
            $warnings[] = ['code' => 'stale_metrics', 'severity' => 'warning', 'age_seconds' => time() - $updatedAt];
        }

        if (($snapshot['online_conn'] ?? 0) === 0 && ($snapshot['websocket'] ?? false)) {
            $warnings[] = ['code' => 'no_active_connections', 'severity' => 'info'];
        }

        return [
            'target' => [
                'type' => 'node',
                'id' => $node->id,
                'name' => $node->name,
            ],
            'online' => (bool) $node->is_online,
            'websocket' => $snapshot['websocket'],
            'kernel' => [
                'running' => array_key_exists('kernel_status', $metrics) ? (bool) $metrics['kernel_status'] : null,
            ],
            'resources' => [
                'cpu_percent' => $cpu,
                'memory_percent' => $this->percent($load['mem'] ?? []),
                'disk_percent' => $this->percent($load['disk'] ?? []),
            ],
            'connections' => [
                'active' => (int) ($snapshot['online_conn'] ?? 0),
            ],
            'traffic' => [
                'inbound_bps' => (int) ($metrics['inbound_speed'] ?? 0),
                'outbound_bps' => (int) ($metrics['outbound_speed'] ?? 0),
                'upload_bytes' => (int) ($node->u ?? 0),
                'download_bytes' => (int) ($node->d ?? 0),
            ],
            'last_seen_at' => $node->last_check_at,
            'warnings' => $warnings,
        ];
    }

    public function trafficSummary(?array $allowedNodeIds = null): array
    {
        $nodes = ServerService::getAllServers();
        if ($allowedNodeIds !== null) {
            $allowed = array_fill_keys(array_map('intval', $allowedNodeIds), true);
            $nodes = $nodes->filter(fn (Server $node) => isset($allowed[(int) $node->id]));
        }

        return [
            'nodes' => $nodes->count(),
            'online_nodes' => $nodes->filter(fn (Server $node) => (bool) $node->is_online)->count(),
            'upload_bytes' => (int) $nodes->sum(fn (Server $node) => (int) ($node->u ?? 0)),
            'download_bytes' => (int) $nodes->sum(fn (Server $node) => (int) ($node->d ?? 0)),
            'active_connections' => (int) $nodes->sum(fn (Server $node) => (int) ($node->online_conn ?? 0)),
            'inbound_bps' => (int) $nodes->sum(fn (Server $node) => (int) (($node->metrics['inbound_speed'] ?? 0))),
            'outbound_bps' => (int) $nodes->sum(fn (Server $node) => (int) (($node->metrics['outbound_speed'] ?? 0))),
        ];
    }

    public function queueStatus(): array
    {
        $masters = app(MasterSupervisorRepository::class)->all();
        $supervisors = app(SupervisorRepository::class)->all();

        return [
            'status' => !empty($masters)
                && !collect($masters)->contains(fn ($master) => ($master->status ?? null) === 'paused'),
            'failed_jobs' => app(JobRepository::class)->countRecentlyFailed(),
            'jobs_per_minute' => app(MetricsRepository::class)->jobsProcessedPerMinute(),
            'processes' => collect($supervisors)->sum(fn ($supervisor) => collect($supervisor->processes ?? [])->sum()),
            'wait' => collect(app(WaitTimeCalculator::class)->calculate())->take(1)->toArray(),
        ];
    }

    public function auditLogs(int $limit = 50, ?array $allowedNodeIds = null): array
    {
        $query = AgentAuditLog::query()->orderByDesc('id');
        if ($allowedNodeIds !== null) {
            $ids = array_map('strval', array_map('intval', $allowedNodeIds));
            $query->where(function ($scoped) use ($ids) {
                $scoped->whereNull('target_type')
                    ->orWhere(function ($nodeQuery) use ($ids) {
                        $nodeQuery->where('target_type', 'node')
                            ->whereIn('target_id', $ids);
                    });
            });
        }

        return $query
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(fn (AgentAuditLog $log) => [
                'request_id' => $log->request_id,
                'client_name' => $log->client_name,
                'tool' => $log->tool,
                'risk_level' => $log->risk_level,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'result_status' => $log->result_status,
                'result_summary' => $log->result_summary,
                'error_code' => $log->error_code,
                'created_at' => $log->created_at,
            ])
            ->toArray();
    }

    private function nodeSnapshot(Server $node): array
    {
        return [
            'id' => $node->id,
            'name' => $node->name,
            'type' => $node->type,
            'host' => $node->host,
            'port' => $node->port,
            'machine_id' => $node->machine_id,
            'enabled' => (bool) $node->enabled,
            'show' => (bool) $node->show,
            'online' => (bool) $node->is_online,
            'websocket' => NodeSyncService::isNodeOnline((int) $node->id),
            'available_status' => $node->available_status,
            'last_check_at' => $node->last_check_at,
            'last_push_at' => $node->last_push_at,
            'online_users' => (int) ($node->online ?? 0),
            'online_conn' => (int) ($node->online_conn ?? 0),
            'upload_bytes' => (int) ($node->u ?? 0),
            'download_bytes' => (int) ($node->d ?? 0),
            'load_status' => $node->load_status,
            'metrics' => $node->metrics,
        ];
    }

    private function percent(array $bucket): ?float
    {
        $total = (float) ($bucket['total'] ?? 0);
        if ($total <= 0) {
            return null;
        }
        return round(((float) ($bucket['used'] ?? 0) / $total) * 100, 2);
    }
}
