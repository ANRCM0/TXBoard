<?php

namespace App\Http\Routes\V2;

use App\Http\Controllers\V2\Agent\AgentOpsController;
use Illuminate\Contracts\Routing\Registrar;

class AgentRoute
{
    public function map(Registrar $router): void
    {
        $router->group([
            'prefix' => 'agent',
            'middleware' => ['agent', 'agent.log', 'throttle:120,1'],
        ], function (Registrar $router): void {
            $router->get('/whoami', [AgentOpsController::class, 'whoami']);
            $router->get('/system/status', [AgentOpsController::class, 'systemStatus']);
            $router->get('/machines', [AgentOpsController::class, 'machines']);
            $router->get('/nodes', [AgentOpsController::class, 'nodes']);
            $router->get('/nodes/{nodeId}/metrics', [AgentOpsController::class, 'nodeMetrics']);
            $router->get('/nodes/{nodeId}/diagnose', [AgentOpsController::class, 'diagnoseNode']);
            $router->get('/traffic/summary', [AgentOpsController::class, 'trafficSummary']);
            $router->get('/queue/status', [AgentOpsController::class, 'queueStatus']);
            $router->get('/audit', [AgentOpsController::class, 'auditLogs']);
            $router->post('/nodes/{nodeId}/actions', [AgentOpsController::class, 'createNodeAction']);
            $router->get('/actions/{requestId}', [AgentOpsController::class, 'actionStatus']);
        });
    }
}
