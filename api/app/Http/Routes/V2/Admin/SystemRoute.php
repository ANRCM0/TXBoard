<?php

namespace App\Http\Routes\V2\Admin;

use App\Http\Controllers\V2\Admin\SystemController;
use Illuminate\Contracts\Routing\Registrar;

class SystemRoute
{
    public function map(Registrar $router): void
    {
        $router->group(['prefix' => 'system'], function (Registrar $router): void {
            $router->get('/getSystemStatus', [SystemController::class, 'getSystemStatus']);
            $router->get('/getQueueStats', [SystemController::class, 'getQueueStats']);
            $router->get('/getQueueWorkload', [SystemController::class, 'getQueueWorkload']);
            $router->get('/getQueueMasters', [SystemController::class, 'getQueueMasters']);
            $router->get('/getHorizonFailedJobs', [SystemController::class, 'getHorizonFailedJobs']);
            $router->any('/getAuditLog', [SystemController::class, 'getAuditLog']);
        });

    }
}
