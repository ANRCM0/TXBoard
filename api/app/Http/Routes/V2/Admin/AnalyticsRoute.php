<?php

namespace App\Http\Routes\V2\Admin;

use App\Http\Controllers\V2\Admin\StatController;
use App\Http\Controllers\V2\Admin\QueueMonitorController;
use Illuminate\Contracts\Routing\Registrar;

class AnalyticsRoute
{
    public function map(Registrar $router): void
    {
        $router->group(['prefix' => 'stat'], function (Registrar $router): void {
            $router->get('/getOverride', [StatController::class, 'getOverride']);
            $router->get('/getStats', [StatController::class, 'getStats']);
            $router->get('/queue/snapshot', [QueueMonitorController::class, 'snapshot']);
            $router->get('/queue/failures', [QueueMonitorController::class, 'failures']);
            $router->get('/queue/failure', [QueueMonitorController::class, 'failure']);
            $router->get('/getServerLastRank', [StatController::class, 'getServerLastRank']);
            $router->get('/getServerYesterdayRank', [StatController::class, 'getServerYesterdayRank']);
            $router->get('/getOrder', [StatController::class, 'getOrder']);
            $router->any('/getStatUser', [StatController::class, 'getStatUser']);
            $router->get('/getRanking', [StatController::class, 'getRanking']);
            $router->get('/getStatRecord', [StatController::class, 'getStatRecord']);
            $router->get('/getTrafficRank', [StatController::class, 'getTrafficRank']);
        });
    }
}
