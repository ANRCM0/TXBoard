<?php

namespace App\Http\Routes\V2\Admin;

use App\Http\Controllers\V2\Admin\AgentOpsController;
use App\Http\Controllers\V2\Admin\AgentSupportController;
use Illuminate\Contracts\Routing\Registrar;

class AgentRoute
{
    public function map(Registrar $router): void
    {
        $router->group(['prefix' => 'agent'], function (Registrar $router): void {
            $router->get('/support/reply-requests', [AgentSupportController::class, 'replies']);
            $router->post('/support/reply-requests/approve', [AgentSupportController::class, 'approve']);
            $router->post('/support/reply-requests/reject', [AgentSupportController::class, 'reject']);
            $router->get('/abilities', [AgentOpsController::class, 'abilities']);
            $router->get('/tokens', [AgentOpsController::class, 'tokens']);
            $router->post('/tokens/create', [AgentOpsController::class, 'createToken']);
            $router->post('/tokens/revoke', [AgentOpsController::class, 'revokeToken']);
            $router->get('/fleet/health', [AgentOpsController::class, 'fleetHealth']);
            $router->get('/inspections', [AgentOpsController::class, 'inspectionList']);
            $router->post('/inspections/run', [AgentOpsController::class, 'runInspection']);
            $router->get('/nodes/{nodeId}/timeline', [AgentOpsController::class, 'nodeTimeline']);
            $router->get('/actions', [AgentOpsController::class, 'actionList']);
            $router->post('/actions/approve', [AgentOpsController::class, 'approveAction']);
            $router->post('/actions/reject', [AgentOpsController::class, 'rejectAction']);
        });
    }
}
