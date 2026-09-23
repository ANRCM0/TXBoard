<?php

namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\Agent\AgentOpsController;
use App\Http\Controllers\V2\Agent\AgentSupportController;
use App\Http\Controllers\V2\Agent\AgentPairingController;
use Illuminate\Contracts\Routing\Registrar;

class AgentRoute
{
    public function map(Registrar $router): void
    {
        $router->post('/agent/pairings/redeem', [AgentPairingController::class, 'redeem'])
            ->middleware('throttle:10,1');

        $router->group([
            'prefix' => 'agent',
            'middleware' => ['agent', 'agent.log', 'throttle:120,1'],
        ], function (Registrar $router): void {
            $router->get('/support/overview', [AgentSupportController::class, 'overview']);
            $router->get('/support/tickets', [AgentSupportController::class, 'tickets']);
            $router->get('/support/tickets/{ticketId}', [AgentSupportController::class, 'ticket']);
            $router->post('/support/tickets/{ticketId}/reply-requests', [AgentSupportController::class, 'requestReply']);
            $router->get('/support/reply-requests/{requestId}', [AgentSupportController::class, 'replyStatus']);
            $router->get('/whoami', [AgentOpsController::class, 'whoami']);
            $router->get('/system/status', [AgentOpsController::class, 'systemStatus']);
            $router->get('/machines', [AgentOpsController::class, 'machines']);
            $router->get('/nodes', [AgentOpsController::class, 'nodes']);
            $router->get('/nodes/{nodeId}/metrics', [AgentOpsController::class, 'nodeMetrics']);
            $router->get('/nodes/{nodeId}/diagnose', [AgentOpsController::class, 'diagnoseNode']);
            $router->get('/fleet/health', [AgentOpsController::class, 'fleetHealth']);
            $router->get('/inspections', [AgentOpsController::class, 'inspectionHistory']);
            $router->get('/nodes/{nodeId}/remediation', [AgentOpsController::class, 'remediationPlan']);
            $router->get('/nodes/{nodeId}/timeline', [AgentOpsController::class, 'incidentTimeline']);
            $router->get('/traffic/summary', [AgentOpsController::class, 'trafficSummary']);
            $router->get('/queue/status', [AgentOpsController::class, 'queueStatus']);
            $router->get('/audit', [AgentOpsController::class, 'auditLogs']);
            $router->post('/nodes/{nodeId}/actions', [AgentOpsController::class, 'createNodeAction']);
            $router->get('/actions/{requestId}', [AgentOpsController::class, 'actionStatus']);
            $router->get('/actions/{requestId}/verify', [AgentOpsController::class, 'verifyAction']);
        });
    }
}
