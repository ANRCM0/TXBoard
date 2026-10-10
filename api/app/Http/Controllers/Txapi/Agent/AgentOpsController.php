<?php

namespace App\Http\Controllers\Txapi\Agent;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\AgentOps\AgentAbility;
use App\Services\AgentOps\AgentActionService;
use App\Services\AgentOps\AgentInsightService;
use App\Services\AgentOps\AgentOpsService;
use App\Services\AgentOps\AgentTargetScope;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AgentOpsController extends Controller
{
    public function __construct(
        private readonly AgentOpsService $ops,
        private readonly AgentActionService $actions,
        private readonly AgentInsightService $insights,
    ) {
    }

    public function whoami(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        return $this->success([
            'admin_id' => $request->user()->id,
            'client_name' => preg_replace('/^agent:/', '', (string) $token->name),
            'token_id' => $token->id,
            'abilities' => AgentTargetScope::functionalAbilities($token),
            'target_scope' => AgentTargetScope::describe($token),
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
        $token = $request->user()->currentAccessToken();
        return $this->success($this->ops->machines(AgentTargetScope::allowedMachineIds($token)));
    }

    public function nodes(Request $request)
    {
        AgentAbility::assert($request, AgentAbility::NODES_READ);
        $token = $request->user()->currentAccessToken();
        return $this->success($this->ops->nodes(AgentTargetScope::allowedNodeIds($token)));
    }

    public function nodeMetrics(Request $request, int $nodeId)
    {
        AgentAbility::assert($request, AgentAbility::METRICS_READ);
        $node = Server::find($nodeId);
        if (!$node) {
            return $this->fail([404000, 'Node not found']);
        }
        AgentTargetScope::assertNode($request, $node);
        return $this->success($this->ops->nodeMetrics($nodeId));
    }

    public function diagnoseNode(Request $request, int $nodeId)
    {
        AgentAbility::assert($request, AgentAbility::NODES_DIAGNOSE);
        $node = Server::find($nodeId);
        if (!$node) {
            return $this->fail([404000, 'Node not found']);
        }
        AgentTargetScope::assertNode($request, $node);
        return $this->success($this->ops->diagnoseNode($nodeId));
    }

    public function fleetHealth(Request $request)
    {
        AgentAbility::assert($request, AgentAbility::INSIGHTS_READ);
        $token = $request->user()->currentAccessToken();

        return $this->success($this->insights->fleetHealth(
            AgentTargetScope::allowedNodeIds($token),
        ));
    }

    public function inspectionHistory(Request $request)
    {
        AgentAbility::assert($request, AgentAbility::INSIGHTS_READ);
        $params = $request->validate(['limit' => 'nullable|integer|min:1|max:50']);
        $token = $request->user()->currentAccessToken();

        return $this->success($this->insights->inspectionHistory(
            (int) ($params['limit'] ?? 20),
            AgentTargetScope::allowedNodeIds($token),
        ));
    }

    public function remediationPlan(Request $request, int $nodeId)
    {
        AgentAbility::assert($request, AgentAbility::INSIGHTS_READ);
        $node = Server::find($nodeId);
        if (!$node) {
            return $this->fail([404000, 'Node not found']);
        }
        AgentTargetScope::assertNode($request, $node);

        return $this->success($this->insights->remediationPlan($nodeId));
    }

    public function incidentTimeline(Request $request, int $nodeId)
    {
        AgentAbility::assert($request, AgentAbility::INSIGHTS_READ);
        $params = $request->validate([
            'hours' => 'nullable|integer|min:1|max:168',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $node = Server::find($nodeId);
        if (!$node) {
            return $this->fail([404000, 'Node not found']);
        }
        AgentTargetScope::assertNode($request, $node);

        return $this->success($this->insights->incidentTimeline(
            $nodeId,
            (int) ($params['hours'] ?? 24),
            (int) ($params['limit'] ?? 100),
        ));
    }

    public function trafficSummary(Request $request)
    {
        AgentAbility::assert($request, AgentAbility::TRAFFIC_READ);
        $token = $request->user()->currentAccessToken();
        return $this->success($this->ops->trafficSummary(AgentTargetScope::allowedNodeIds($token)));
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
        $token = $request->user()->currentAccessToken();
        return $this->success($this->ops->auditLogs(
            (int) ($params['limit'] ?? 50),
            AgentTargetScope::allowedNodeIds($token),
        ));
    }

    public function createNodeAction(Request $request, int $nodeId)
    {
        $params = $request->validate([
            'action' => 'required|string|max:64',
            'input' => 'nullable|array',
        ]);

        try {
            $definition = $this->actions->definition($params['action']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['action' => $e->getMessage()]);
        }
        AgentAbility::assert($request, $definition['ability']);

        $node = Server::find($nodeId);
        if (!$node) {
            return $this->fail([404000, 'Node not found']);
        }
        AgentTargetScope::assertNode($request, $node);

        try {
            $action = $this->actions->createPending(
            $request->user(),
            $request->user()->currentAccessToken(),
            $node,
            $params['action'],
            $params['input'] ?? [],
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['action' => $e->getMessage()]);
        }

        return $this->success($this->actions->serialize($action));
    }

    public function verifyAction(Request $request, string $requestId)
    {
        AgentAbility::assert($request, AgentAbility::INSIGHTS_READ);

        try {
            $action = $this->actions->find($requestId);
        } catch (\InvalidArgumentException) {
            return $this->fail([404000, 'Agent action not found']);
        }

        if ((int) $action->admin_id !== (int) $request->user()->id) {
            return $this->fail([403000, 'Forbidden']);
        }

        $node = Server::find((int) $action->node_id);
        if ($node) {
            AgentTargetScope::assertNode($request, $node);
        }

        return $this->success($this->insights->verifyAction($requestId));
    }

    public function actionStatus(Request $request, string $requestId)
    {
        AgentAbility::assert($request, AgentAbility::NODES_READ);
        try {
            $action = $this->actions->find($requestId);
        } catch (\InvalidArgumentException) {
            return $this->fail([404000, 'Agent action not found']);
        }

        if ((int) $action->admin_id !== (int) $request->user()->id) {
            return $this->fail([403000, 'Forbidden']);
        }
        $node = Server::find((int) $action->node_id);
        if ($node) {
            AgentTargetScope::assertNode($request, $node);
        }

        return $this->success($this->actions->serialize($action));
    }
}
