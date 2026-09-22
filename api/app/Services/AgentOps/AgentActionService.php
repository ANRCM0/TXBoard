<?php

namespace App\Services\AgentOps;

use App\Models\AgentAction;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentActionService
{
    public const DEFINITIONS = [
        'node.full_sync' => [
            'ability' => AgentAbility::NODES_SYNC,
            'risk' => 'operate',
            'event' => null,
        ],
        'ops.kernel.status' => [
            'ability' => AgentAbility::NODES_DIAGNOSE,
            'risk' => 'operate',
            'event' => 'ops.kernel.status',
        ],
        'ops.kernel.restart' => [
            'ability' => AgentAbility::NODES_OPERATE,
            'risk' => 'operate',
            'event' => 'ops.kernel.restart',
        ],
        'ops.config.validate' => [
            'ability' => AgentAbility::NODES_DIAGNOSE,
            'risk' => 'operate',
            'event' => 'ops.config.validate',
        ],
        'ops.config.reload' => [
            'ability' => AgentAbility::NODES_OPERATE,
            'risk' => 'operate',
            'event' => 'ops.config.reload',
        ],
        'ops.system.info' => [
            'ability' => AgentAbility::NODES_DIAGNOSE,
            'risk' => 'operate',
            'event' => 'ops.system.info',
        ],
        'ops.network.dns' => [
            'ability' => AgentAbility::NODES_DIAGNOSE,
            'risk' => 'operate',
            'event' => 'ops.network.dns',
        ],
        'ops.network.port_check' => [
            'ability' => AgentAbility::NODES_DIAGNOSE,
            'risk' => 'operate',
            'event' => 'ops.network.port_check',
        ],
        'ops.logs.tail' => [
            'ability' => AgentAbility::NODES_DIAGNOSE,
            'risk' => 'operate',
            'event' => 'ops.logs.tail',
        ],
    ];

    public function definition(string $action): array
    {
        if (!isset(self::DEFINITIONS[$action])) {
            throw new \InvalidArgumentException('Unsupported Agent action');
        }
        return self::DEFINITIONS[$action];
    }

    public function createPending(User $admin, object $token, Server $node, string $action, array $input): AgentAction
    {
        $definition = $this->definition($action);
        $input = $this->validateInput($node, $action, $input);
        $tokenId = $token->id ?? null;

        $pendingSameAction = AgentAction::query()
            ->where('token_id', $tokenId)
            ->where('node_id', $node->id)
            ->where('action', $action)
            ->where('status', AgentAction::STATUS_PENDING)
            ->orderByDesc('id')
            ->first();

        if ($pendingSameAction) {
            if ($pendingSameAction->input === $input) {
                return $pendingSameAction;
            }
            throw new \InvalidArgumentException('A different request for this action is already pending');
        }

        $maxPendingPerToken = max(1, (int) config('agent_ops.max_pending_per_token', 20));
        $pendingPerToken = AgentAction::query()
            ->where('token_id', $tokenId)
            ->where('status', AgentAction::STATUS_PENDING)
            ->count();
        if ($pendingPerToken >= $maxPendingPerToken) {
            throw new \InvalidArgumentException('Agent pending-action limit reached');
        }

        $maxPendingPerNode = max(1, (int) config('agent_ops.max_pending_per_node', 5));
        $pendingPerNode = AgentAction::query()
            ->where('node_id', $node->id)
            ->where('status', AgentAction::STATUS_PENDING)
            ->count();
        if ($pendingPerNode >= $maxPendingPerNode) {
            throw new \InvalidArgumentException('Node pending-action limit reached');
        }

        $cooldown = max(0, (int) config('agent_ops.action_cooldown', 30));
        if ($cooldown > 0) {
            $recent = AgentAction::query()
                ->where('token_id', $tokenId)
                ->where('node_id', $node->id)
                ->where('action', $action)
                ->whereIn('status', [
                    AgentAction::STATUS_RUNNING,
                    AgentAction::STATUS_SUCCEEDED,
                    AgentAction::STATUS_FAILED,
                    AgentAction::STATUS_TIMED_OUT,
                ])
                ->where('created_at', '>=', time() - $cooldown)
                ->exists();

            if ($recent) {
                throw new \InvalidArgumentException("Action cooldown is active ({$cooldown}s)");
            }
        }

        return AgentAction::create([
            'request_id' => 'ops_' . Str::lower((string) Str::ulid()),
            'admin_id' => $admin->id,
            'token_id' => $tokenId,
            'node_id' => $node->id,
            'action' => $action,
            'risk_level' => $definition['risk'],
            'status' => AgentAction::STATUS_PENDING,
            'input' => $input,
        ]);
    }

    public function approve(string $requestId, User $approver): AgentAction
    {
        $action = DB::transaction(function () use ($requestId, $approver) {
            /** @var AgentAction|null $action */
            $action = AgentAction::where('request_id', $requestId)->lockForUpdate()->first();
            if (!$action) {
                throw new \InvalidArgumentException('Agent action not found');
            }
            if ($action->status !== AgentAction::STATUS_PENDING) {
                return $action;
            }

            if (!NodeSyncService::isNodeOnline((int) $action->node_id)) {
                $action->update([
                    'status' => AgentAction::STATUS_FAILED,
                    'error_code' => 'node_offline',
                    'result' => ['message' => 'Node websocket is offline'],
                    'approved_by' => $approver->id,
                    'approved_at' => time(),
                    'finished_at' => time(),
                ]);
                return $action->fresh();
            }

            $action->update([
                'status' => AgentAction::STATUS_RUNNING,
                'approved_by' => $approver->id,
                'approved_at' => time(),
                'started_at' => time(),
            ]);
            return $action->fresh();
        });

        if ($action->status !== AgentAction::STATUS_RUNNING) {
            return $action;
        }

        if ($action->action === 'node.full_sync') {
            NodeSyncService::notifyFullSync((int) $action->node_id);
            $action->update([
                'status' => AgentAction::STATUS_SUCCEEDED,
                'result' => ['dispatched' => true, 'verified_websocket_online' => true],
                'finished_at' => time(),
            ]);
            return $action->fresh();
        }

        $definition = $this->definition($action->action);
        NodeSyncService::push((int) $action->node_id, $definition['event'], [
            'request_id' => $action->request_id,
            'args' => $action->input ?? [],
        ]);

        return $action->fresh();
    }

    public function reject(string $requestId, User $approver, ?string $reason = null): AgentAction
    {
        /** @var AgentAction|null $action */
        $action = AgentAction::where('request_id', $requestId)->first();
        if (!$action) {
            throw new \InvalidArgumentException('Agent action not found');
        }
        if ($action->status !== AgentAction::STATUS_PENDING) {
            return $action;
        }

        $action->update([
            'status' => AgentAction::STATUS_REJECTED,
            'approved_by' => $approver->id,
            'approved_at' => time(),
            'finished_at' => time(),
            'result' => ['reason' => $reason ?: 'Rejected by administrator'],
        ]);

        return $action->fresh();
    }

    public function find(string $requestId): AgentAction
    {
        /** @var AgentAction|null $action */
        $action = AgentAction::where('request_id', $requestId)->first();
        if (!$action) {
            throw new \InvalidArgumentException('Agent action not found');
        }
        return $this->refreshTimeout($action);
    }

    public function handleNodeResult(int $nodeId, array $data): void
    {
        $requestId = (string) ($data['request_id'] ?? '');
        if ($requestId === '') {
            return;
        }

        /** @var AgentAction|null $action */
        $action = AgentAction::query()
            ->where('request_id', $requestId)
            ->where('node_id', $nodeId)
            ->first();

        if (!$action || $action->status !== AgentAction::STATUS_RUNNING) {
            return;
        }

        $ok = (bool) ($data['ok'] ?? false);
        $action->update([
            'status' => $ok ? AgentAction::STATUS_SUCCEEDED : AgentAction::STATUS_FAILED,
            'result' => is_array($data['result'] ?? null) ? $data['result'] : [
                'message' => $data['message'] ?? null,
            ],
            'error_code' => $ok ? null : (string) ($data['error_code'] ?? 'node_operation_failed'),
            'finished_at' => time(),
        ]);
    }

    public function refreshTimeout(AgentAction $action): AgentAction
    {
        $timeout = (int) config('agent_ops.action_timeout', 120);
        if ($action->status === AgentAction::STATUS_RUNNING
            && $action->started_at
            && time() - (int) $action->started_at > $timeout) {
            $action->update([
                'status' => AgentAction::STATUS_TIMED_OUT,
                'error_code' => 'operation_timeout',
                'finished_at' => time(),
            ]);
            return $action->fresh();
        }
        return $action;
    }

    public function serialize(AgentAction $action): array
    {
        $action = $this->refreshTimeout($action);
        return [
            'request_id' => $action->request_id,
            'node_id' => $action->node_id,
            'action' => $action->action,
            'risk_level' => $action->risk_level,
            'status' => $action->status,
            'input' => $action->input,
            'result' => $action->result,
            'error_code' => $action->error_code,
            'approved_by' => $action->approved_by,
            'approved_at' => $action->approved_at,
            'started_at' => $action->started_at,
            'finished_at' => $action->finished_at,
            'created_at' => $action->created_at,
        ];
    }

    private function validateInput(Server $node, string $action, array $input): array
    {
        if ($action === 'ops.network.dns') {
            $target = strtolower(trim((string) ($input['target'] ?? $node->host)));
            if (!$this->networkTargetAllowed($node, $target)) {
                throw new \InvalidArgumentException('Network diagnostic target is not allowed');
            }
            return ['target' => $target];
        }

        if ($action === 'ops.network.port_check') {
            $target = strtolower(trim((string) ($input['target'] ?? $node->host)));
            $port = (int) ($input['port'] ?? (is_numeric($node->port) ? $node->port : 0));
            if (!$this->networkTargetAllowed($node, $target)) {
                throw new \InvalidArgumentException('Network diagnostic target is not allowed');
            }
            if ($port < 1 || $port > 65535) {
                throw new \InvalidArgumentException('Port must be between 1 and 65535');
            }
            return ['target' => $target, 'port' => $port];
        }

        if ($action === 'ops.logs.tail') {
            $source = strtolower(trim((string) ($input['source'] ?? 'application')));
            if ($source !== 'application') {
                throw new \InvalidArgumentException('Unsupported log source');
            }

            $maxLines = max(1, (int) config('agent_ops.log_max_lines', 200));
            $lines = (int) ($input['lines'] ?? min(100, $maxLines));
            if ($lines < 1 || $lines > $maxLines) {
                throw new \InvalidArgumentException("Log lines must be between 1 and {$maxLines}");
            }

            return [
                'source' => 'application',
                'lines' => $lines,
                'max_bytes' => max(1024, (int) config('agent_ops.log_max_bytes', 65536)),
            ];
        }

        return [];
    }

    private function networkTargetAllowed(Server $node, string $target): bool
    {
        $target = rtrim(strtolower($target), '.');
        $nodeHost = rtrim(strtolower((string) $node->host), '.');
        if ($target !== '' && hash_equals($nodeHost, $target)) {
            return true;
        }

        foreach ((array) config('agent_ops.network_allowlist', []) as $allowed) {
            $allowed = rtrim(strtolower(trim((string) $allowed)), '.');
            if ($allowed === '') {
                continue;
            }
            if (str_starts_with($allowed, '*.')) {
                $suffix = substr($allowed, 1);
                if (str_ends_with($target, $suffix) && $target !== ltrim($suffix, '.')) {
                    return true;
                }
                continue;
            }
            if (hash_equals($allowed, $target)) {
                return true;
            }
        }

        return false;
    }
}
