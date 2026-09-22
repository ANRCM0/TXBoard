<?php

namespace App\Http\Controllers\V2\Agent;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\AgentOps\AgentAbility;
use App\Services\AgentOps\AgentActionService;
use App\Services\AgentOps\AgentOpsService;
use Illuminate\Http\Request;

class AgentOpsController extends Controller
{
    public function __construct(
        private readonly AgentOpsService $ops,
        private readonly AgentActionService $actions,
    ) {
    }

    public function whoami(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        return $this->success([
            'admin_id' => $request->user()->id,
            'client_name' => preg_replace('/^agent:/', '', (string) $token->name),
            'token_id' => $token->id,
            'abilities' => $token->abilities ?? [],
        ]);
    }

    public function systemStatus(Request $request)
    {
        AgentAbility::assert($request, AgentAbility::SYSTEM_READ);
        return $this->success($this->ops->systemStatus());
    }

    public function machines(Request $request)
    {
        AgentAbility::assert($request, AgentAbility::MACHINES_READ);
        return $this->success($this->ops->machines());
    }

    public function nodes(Request $request)
    {
        AgentAbility::assert($request, AgentAbility::NODES_READ);
        return $this->success($this->ops->nodes());
    }

    public function nodeMetrics(Request $request, int $nodeId)
    {
        AgentAbility::assert($request, AgentAbility::METRICS_READ);
        return $this->success($this->ops->nodeMetrics($nodeId));
    }

    public function diagnoseNode(Request $request, int $nodeId)
    {
        AgentAbility::assert($request, AgentAbility::NODES_DIAGNOSE);
        return $this->success($this->ops->diagnoseNode($nodeId));
    }

    public function trafficSummary(Request $request)
    {
        AgentAbility::assert($request, AgentAbility::TRAFFIC_READ);
        return $this->success($this->ops->trafficSummary());
    }

    public function queueStatus(Request $request)
    {
        AgentAbility::assert($request, AgentAbility::SYSTEM_READ);
        return $this->success($this->ops->queueStatus());
    }

    public function auditLogs(Request $request)
    {
        AgentAbility::assert($request, AgentAbility::AUDIT_READ);
        $params = $request->validate(['limit' => 'nullable|integer|min:1|max:100']);
        return $this->success($this->ops->auditLogs((int) ($params['limit'] ?? 50)));
    }

    public function createNodeAction(Request $request, int $nodeId)
    {
        $params = $request->validate([
            'action' => 'required|string|max:64',
            'input' => 'nullable|array',
        ]);

        $definition = $this->actions->definition($params['action']);
        AgentAbility::assert($request, $definition['ability']);

        $node = Server::find($nodeId);
        if (!$node) {
            return $this->fail([404000, 'Node not found']);
        }

        $action = $this->actions->createPending(
            $request->user(),
            $request->user()->currentAccessToken(),
            $node,
            $params['action'],
            $params['input'] ?? [],
        );

        return $this->success($this->actions->serialize($action));
    }

    public function actionStatus(Request $request, string $requestId)
    {
        AgentAbility::assert($request, AgentAbility::NODES_READ);
        $action = $this->actions->find($requestId);

        if ((int) $action->admin_id !== (int) $request->user()->id) {
            return $this->fail([403000, 'Forbidden']);
        }

        return $this->success($this->actions->serialize($action));
    }
}
