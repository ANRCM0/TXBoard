<?php

namespace App\Services\AgentOps;

use App\Models\AgentAction;
use App\Models\AgentAuditLog;
use App\Models\AgentInspection;
use App\Models\Server;
use Illuminate\Support\Str;

class AgentInsightService
{
    public function __construct(
        private readonly AgentOpsService $ops,
        private readonly AgentActionService $actions,
    ) {
    }

    public function fleetHealth(?array $allowedNodeIds = null): array
    {
        $inventory = $this->ops->nodes($allowedNodeIds);
        $nodes = [];

        foreach ($inventory as $item) {
            $diagnosis = $this->ops->diagnoseNode((int) $item['id']);
            $nodes[] = $this->findingFromDiagnosis($diagnosis);
        }

        $summary = $this->summarizeFindings($nodes);

        return [
            'status' => $summary['status'],
            'summary' => $summary,
            'nodes' => $nodes,
            'generated_at' => time(),
        ];
    }

    public function runInspection(string $source = 'schedule'): array
    {
        $startedAt = time();
        $fleet = $this->fleetHealth();
        $finishedAt = time();

        $inspection = AgentInspection::create([
            'inspection_id' => 'insp_' . Str::lower((string) Str::ulid()),
            'source' => $source,
            'status' => $fleet['status'],
            'summary' => $fleet['summary'],
            'findings' => $fleet['nodes'],
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);

        return $this->serializeInspection($inspection);
    }

    public function inspectionHistory(int $limit = 20, ?array $allowedNodeIds = null): array
    {
        return AgentInspection::query()
            ->orderByDesc('id')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(fn (AgentInspection $inspection) => $this->serializeInspection($inspection, $allowedNodeIds))
            ->toArray();
    }

    public function pruneInspections(int $retentionDays): int
    {
        $retentionDays = max(1, $retentionDays);
        return AgentInspection::query()
            ->where('created_at', '<', time() - ($retentionDays * 86400))
            ->delete();
    }

    public function remediationPlan(int $nodeId): array
    {
        $diagnosis = $this->ops->diagnoseNode($nodeId);
        $recommendations = [];

        foreach ($diagnosis['warnings'] as $warning) {
            $code = (string) ($warning['code'] ?? '');
            $recommendation = $this->recommendationForWarning($code);
            if ($recommendation !== null) {
                $recommendations[] = $recommendation;
            }
        }

        if ($recommendations === []) {
            $recommendations[] = [
                'priority' => 'none',
                'reason_code' => 'no_actionable_warning',
                'summary' => 'No actionable warning is currently detected.',
                'suggested_tool' => null,
                'suggested_action' => null,
                'approval_required' => false,
                'automatic_execution' => false,
            ];
        }

        usort($recommendations, fn (array $a, array $b) =>
            $this->priorityWeight($b['priority']) <=> $this->priorityWeight($a['priority'])
        );

        return [
            'target' => $diagnosis['target'],
            'diagnosis' => $diagnosis,
            'recommendations' => $recommendations,
            'automatic_remediation_enabled' => false,
            'policy' => 'recommend_then_approve_then_execute_then_verify',
            'generated_at' => time(),
        ];
    }

    public function verifyAction(string $requestId): array
    {
        $action = $this->actions->find($requestId);
        $serialized = $this->actions->serialize($action);

        if (in_array($action->status, [AgentAction::STATUS_PENDING, AgentAction::STATUS_RUNNING], true)) {
            return [
                'request_id' => $action->request_id,
                'action' => $action->action,
                'action_status' => $action->status,
                'verification_status' => 'waiting',
                'checks' => [],
                'verified_at' => time(),
            ];
        }

        if ($action->status !== AgentAction::STATUS_SUCCEEDED) {
            return [
                'request_id' => $action->request_id,
                'action' => $action->action,
                'action_status' => $action->status,
                'verification_status' => 'action_not_successful',
                'checks' => [],
                'error_code' => $action->error_code,
                'result' => $action->result,
                'verified_at' => time(),
            ];
        }

        $node = Server::find((int) $action->node_id);
        if (!$node) {
            return [
                'request_id' => $action->request_id,
                'action' => $action->action,
                'action_status' => $action->status,
                'verification_status' => 'inconclusive',
                'checks' => [[
                    'name' => 'target_exists',
                    'passed' => null,
                    'observed' => false,
                ]],
                'error_code' => 'target_missing',
                'verified_at' => time(),
            ];
        }

        $diagnosis = $this->ops->diagnoseNode((int) $action->node_id);
        $checks = [];

        $addCheck = function (string $name, ?bool $passed, mixed $observed = null) use (&$checks): void {
            $checks[] = [
                'name' => $name,
                'passed' => $passed,
                'observed' => $observed,
            ];
        };

        switch ($action->action) {
            case 'ops.kernel.restart':
                $addCheck('websocket_connected', (bool) $diagnosis['websocket'], $diagnosis['websocket']);
                $kernel = $diagnosis['kernel']['running'] ?? null;
                $addCheck('kernel_running', is_bool($kernel) ? $kernel : null, $kernel);
                break;

            case 'ops.config.reload':
                $addCheck('websocket_connected', (bool) $diagnosis['websocket'], $diagnosis['websocket']);
                $kernel = $diagnosis['kernel']['running'] ?? null;
                $addCheck('kernel_not_reported_failed', $kernel === null ? null : $kernel !== false, $kernel);
                break;

            case 'node.full_sync':
                $addCheck('websocket_connected', (bool) $diagnosis['websocket'], $diagnosis['websocket']);
                break;

            default:
                $addCheck('operation_result_received', $action->result !== null, $action->result !== null);
                break;
        }

        $verificationStatus = 'passed';
        if (collect($checks)->contains(fn (array $check) => $check['passed'] === false)) {
            $verificationStatus = 'failed';
        } elseif (collect($checks)->contains(fn (array $check) => $check['passed'] === null)) {
            $verificationStatus = 'inconclusive';
        }

        return [
            'request_id' => $action->request_id,
            'action' => $action->action,
            'action_status' => $action->status,
            'verification_status' => $verificationStatus,
            'checks' => $checks,
            'current_diagnosis' => $diagnosis,
            'action_result' => $serialized['result'],
            'verified_at' => time(),
        ];
    }

    public function incidentTimeline(int $nodeId, int $hours = 24, int $limit = 100): array
    {
        $node = Server::find($nodeId);
        if (!$node) {
            throw new \InvalidArgumentException('Node not found');
        }

        $hours = max(1, min(168, $hours));
        $limit = max(1, min(100, $limit));
        $since = time() - ($hours * 3600);
        $events = [];

        $actions = AgentAction::query()
            ->where('node_id', $nodeId)
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->get();

        foreach ($actions as $action) {
            $events[] = [
                'timestamp' => (int) $action->getRawOriginal('created_at'),
                'type' => 'action_requested',
                'severity' => 'info',
                'request_id' => $action->request_id,
                'summary' => $action->action . ' requested',
                'data' => ['action' => $action->action, 'status' => $action->status],
            ];

            if ($action->approved_at) {
                $events[] = [
                    'timestamp' => (int) $action->approved_at,
                    'type' => 'action_approved',
                    'severity' => 'info',
                    'request_id' => $action->request_id,
                    'summary' => $action->action . ' approved',
                    'data' => ['approved_by' => $action->approved_by],
                ];
            }

            if ($action->finished_at) {
                $events[] = [
                    'timestamp' => (int) $action->finished_at,
                    'type' => 'action_finished',
                    'severity' => $action->status === AgentAction::STATUS_SUCCEEDED ? 'info' : 'warning',
                    'request_id' => $action->request_id,
                    'summary' => $action->action . ' ' . $action->status,
                    'data' => [
                        'status' => $action->status,
                        'error_code' => $action->error_code,
                    ],
                ];
            }
        }

        $audits = AgentAuditLog::query()
            ->where('target_type', 'node')
            ->where('target_id', (string) $nodeId)
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($audits as $audit) {
            $events[] = [
                'timestamp' => (int) $audit->getRawOriginal('created_at'),
                'type' => 'agent_request',
                'severity' => $audit->result_status === 'succeeded' ? 'info' : 'warning',
                'request_id' => $audit->request_id,
                'summary' => $audit->tool . ' ' . $audit->result_status,
                'data' => [
                    'client_name' => $audit->client_name,
                    'protocol' => $audit->protocol,
                    'tool' => $audit->tool,
                    'result_status' => $audit->result_status,
                    'error_code' => $audit->error_code,
                ],
            ];
        }

        $inspections = AgentInspection::query()
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->limit(500)
            ->get();

        $lastSignature = null;
        foreach ($inspections as $inspection) {
            $finding = $this->findingForNode((array) $inspection->findings, $nodeId);
            if ($finding === null) {
                continue;
            }

            $warningCodes = array_values(array_map(
                fn (array $warning) => (string) ($warning['code'] ?? ''),
                $finding['warnings'] ?? []
            ));
            sort($warningCodes);
            $signature = ($finding['status'] ?? 'unknown') . '|' . implode(',', $warningCodes);

            if ($signature === $lastSignature) {
                continue;
            }
            $lastSignature = $signature;

            $events[] = [
                'timestamp' => (int) $inspection->finished_at,
                'type' => 'inspection_state_changed',
                'severity' => ($finding['status'] ?? 'healthy') === 'critical'
                    ? 'critical'
                    : (($finding['status'] ?? 'healthy') === 'degraded' ? 'warning' : 'info'),
                'request_id' => $inspection->inspection_id,
                'summary' => 'inspection state: ' . ($finding['status'] ?? 'unknown'),
                'data' => [
                    'status' => $finding['status'] ?? 'unknown',
                    'warning_codes' => $warningCodes,
                    'source' => $inspection->source,
                ],
            ];
        }

        usort($events, fn (array $a, array $b) => $b['timestamp'] <=> $a['timestamp']);

        return [
            'target' => ['type' => 'node', 'id' => $node->id, 'name' => $node->name],
            'window_hours' => $hours,
            'events' => array_slice($events, 0, $limit),
            'generated_at' => time(),
        ];
    }

    private function serializeInspection(AgentInspection $inspection, ?array $allowedNodeIds = null): array
    {
        $findings = array_values((array) ($inspection->findings ?? []));

        if ($allowedNodeIds !== null) {
            $allowed = array_fill_keys(array_map('intval', $allowedNodeIds), true);
            $findings = array_values(array_filter(
                $findings,
                fn (array $finding) => isset($allowed[(int) ($finding['node_id'] ?? 0)])
            ));
        }

        $summary = $this->summarizeFindings($findings);

        return [
            'inspection_id' => $inspection->inspection_id,
            'source' => $inspection->source,
            'status' => $summary['status'],
            'summary' => $summary,
            'findings' => $findings,
            'started_at' => $inspection->started_at,
            'finished_at' => $inspection->finished_at,
            'created_at' => $inspection->getRawOriginal('created_at'),
        ];
    }

    private function findingFromDiagnosis(array $diagnosis): array
    {
        $status = 'healthy';
        foreach ($diagnosis['warnings'] ?? [] as $warning) {
            if (($warning['severity'] ?? null) === 'critical') {
                $status = 'critical';
                break;
            }
            if (($warning['severity'] ?? null) === 'warning') {
                $status = 'degraded';
            }
        }

        return [
            'node_id' => (int) $diagnosis['target']['id'],
            'name' => (string) $diagnosis['target']['name'],
            'status' => $status,
            'online' => (bool) ($diagnosis['online'] ?? false),
            'websocket' => (bool) ($diagnosis['websocket'] ?? false),
            'kernel_running' => $diagnosis['kernel']['running'] ?? null,
            'resources' => $diagnosis['resources'] ?? [],
            'connections' => $diagnosis['connections'] ?? [],
            'traffic' => $diagnosis['traffic'] ?? [],
            'last_seen_at' => $diagnosis['last_seen_at'] ?? null,
            'warnings' => array_values($diagnosis['warnings'] ?? []),
        ];
    }

    private function summarizeFindings(array $findings): array
    {
        $summary = [
            'status' => 'healthy',
            'total_nodes' => count($findings),
            'healthy_nodes' => 0,
            'degraded_nodes' => 0,
            'critical_nodes' => 0,
            'warning_count' => 0,
        ];

        foreach ($findings as $finding) {
            $status = $finding['status'] ?? 'healthy';
            if ($status === 'critical') {
                $summary['critical_nodes']++;
            } elseif ($status === 'degraded') {
                $summary['degraded_nodes']++;
            } else {
                $summary['healthy_nodes']++;
            }
            $summary['warning_count'] += count($finding['warnings'] ?? []);
        }

        if ($summary['critical_nodes'] > 0) {
            $summary['status'] = 'critical';
        } elseif ($summary['degraded_nodes'] > 0) {
            $summary['status'] = 'degraded';
        }

        return $summary;
    }

    private function findingForNode(array $findings, int $nodeId): ?array
    {
        foreach ($findings as $finding) {
            if ((int) ($finding['node_id'] ?? 0) === $nodeId) {
                return $finding;
            }
        }
        return null;
    }

    private function recommendationForWarning(string $code): ?array
    {
        return match ($code) {
            'websocket_offline' => [
                'priority' => 'critical',
                'reason_code' => $code,
                'summary' => 'TX-Node control channel is offline. Node-executed remediation cannot be trusted until connectivity is restored.',
                'suggested_tool' => 'txboard_incident_timeline',
                'suggested_action' => null,
                'approval_required' => false,
                'automatic_execution' => false,
                'operator_note' => 'Check TX-Node process, panel URL, network path and firewall from the host side.',
            ],
            'kernel_not_running' => [
                'priority' => 'critical',
                'reason_code' => $code,
                'summary' => 'The control channel is available but the managed proxy kernel reports not running.',
                'suggested_tool' => 'txboard_restart_kernel',
                'suggested_action' => 'ops.kernel.restart',
                'approval_required' => true,
                'automatic_execution' => false,
                'verify_with' => 'txboard_verify_action',
            ],
            'high_cpu', 'high_memory' => [
                'priority' => 'warning',
                'reason_code' => $code,
                'summary' => 'Resource utilization is high. Inspect metrics and a bounded application-log tail before changing runtime state.',
                'suggested_tool' => 'txboard_tail_logs',
                'suggested_action' => 'ops.logs.tail',
                'approval_required' => true,
                'automatic_execution' => false,
            ],
            'high_disk' => [
                'priority' => 'warning',
                'reason_code' => $code,
                'summary' => 'Disk utilization is high. Inspect bounded application logs and host storage; Agent Ops does not perform automatic deletion.',
                'suggested_tool' => 'txboard_tail_logs',
                'suggested_action' => 'ops.logs.tail',
                'approval_required' => true,
                'automatic_execution' => false,
            ],
            'stale_metrics' => [
                'priority' => 'warning',
                'reason_code' => $code,
                'summary' => 'Node telemetry is stale. Re-check node metrics and control-channel state before remediation.',
                'suggested_tool' => 'txboard_node_metrics',
                'suggested_action' => null,
                'approval_required' => false,
                'automatic_execution' => false,
            ],
            'no_active_connections' => [
                'priority' => 'info',
                'reason_code' => $code,
                'summary' => 'No active connections are currently reported. Correlate with traffic and expected demand before treating this as an incident.',
                'suggested_tool' => 'txboard_traffic_summary',
                'suggested_action' => null,
                'approval_required' => false,
                'automatic_execution' => false,
            ],
            default => null,
        };
    }

    private function priorityWeight(string $priority): int
    {
        return match ($priority) {
            'critical' => 3,
            'warning' => 2,
            'info' => 1,
            default => 0,
        };
    }
}
