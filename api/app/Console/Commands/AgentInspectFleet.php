<?php

namespace App\Console\Commands;

use App\Services\AgentOps\AgentInsightService;
use Illuminate\Console\Command;

class AgentInspectFleet extends Command
{
    protected $signature = 'agent:inspect-fleet {--source=schedule : Inspection source label} {--prune-days= : Override retention days}';

    protected $description = 'Run a TXBoard Agent Ops fleet health inspection';

    public function handle(AgentInsightService $insights): int
    {
        $source = trim((string) $this->option('source')) ?: 'schedule';
        if (!preg_match('/^[a-z0-9._-]{1,24}$/i', $source)) {
            $this->error('Invalid inspection source');
            return self::FAILURE;
        }

        $inspection = $insights->runInspection($source);

        $days = $this->option('prune-days');
        $retentionDays = $days === null
            ? (int) config('agent_ops.inspection_retention_days', 7)
            : max(1, (int) $days);
        $pruned = $insights->pruneInspections($retentionDays);

        $this->info(sprintf(
            'Inspection %s: status=%s nodes=%d critical=%d degraded=%d healthy=%d pruned=%d',
            $inspection['inspection_id'],
            $inspection['status'],
            $inspection['summary']['total_nodes'] ?? 0,
            $inspection['summary']['critical_nodes'] ?? 0,
            $inspection['summary']['degraded_nodes'] ?? 0,
            $inspection['summary']['healthy_nodes'] ?? 0,
            $pruned,
        ));

        return self::SUCCESS;
    }
}
