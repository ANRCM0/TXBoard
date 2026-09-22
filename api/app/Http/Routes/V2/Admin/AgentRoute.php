<?php

namespace App\Http\Routes\V2\Admin;

use App\Http\Controllers\V2\Admin\AgentOpsController;
use Illuminate\Contracts\Routing\Registrar;

class AgentRoute
{
    public function map(Registrar $router): void
    {
        $router->group(['prefix' => 'agent'], function (Registrar $router): void {
            $router->get('/abilities', [AgentOpsController::class, 'abilities']);
            $router->get('/tokens', [AgentOpsController::class, 'tokens']);
            $router->post('/tokens/create', [AgentOpsController::class, 'createToken']);
            $router->post('/tokens/revoke', [AgentOpsController::class, 'revokeToken']);
            $router->get('/actions', [AgentOpsController::class, 'actionList']);
            $router->post('/actions/approve', [AgentOpsController::class, 'approveAction']);
            $router->post('/actions/reject', [AgentOpsController::class, 'rejectAction']);
        });
    }
}
