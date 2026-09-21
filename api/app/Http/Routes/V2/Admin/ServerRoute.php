<?php

namespace App\Http\Routes\V2\Admin;

use App\Http\Controllers\V2\Admin\Server\GroupController;
use App\Http\Controllers\V2\Admin\Server\MachineController;
use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Http\Controllers\V2\Admin\Server\RouteController;
use Illuminate\Contracts\Routing\Registrar;

class ServerRoute
{
    public function map(Registrar $router): void
    {
        $router->group(['prefix' => 'server/group'], function (Registrar $router): void {
            $router->get('/fetch', [GroupController::class, 'fetch']);
            $router->post('/save', [GroupController::class, 'save']);
            $router->post('/drop', [GroupController::class, 'drop']);
        });

        $router->group(['prefix' => 'server/route'], function (Registrar $router): void {
            $router->get('/fetch', [RouteController::class, 'fetch']);
            $router->post('/save', [RouteController::class, 'save']);
            $router->post('/drop', [RouteController::class, 'drop']);
        });

        $router->group(['prefix' => 'server/manage'], function (Registrar $router): void {
            $router->get('/getNodes', [ManageController::class, 'getNodes']);
            $router->post('/update', [ManageController::class, 'update']);
            $router->post('/save', [ManageController::class, 'save']);
            $router->post('/drop', [ManageController::class, 'drop']);
            $router->post('/copy', [ManageController::class, 'copy']);
            $router->post('/sort', [ManageController::class, 'sort']);
            $router->post('/batchDelete', [ManageController::class, 'batchDelete']);
            $router->post('/batchUpdate', [ManageController::class, 'batchUpdate']);
            $router->post('/resetTraffic', [ManageController::class, 'resetTraffic']);
            $router->post('/batchResetTraffic', [ManageController::class, 'batchResetTraffic']);
            $router->get('/generateEchKey', [ManageController::class, 'generateEchKey']);
        });

        $router->group(['prefix' => 'server/machine'], function (Registrar $router): void {
            $router->get('/fetch', [MachineController::class, 'fetch']);
            $router->post('/save', [MachineController::class, 'save']);
            $router->post('/drop', [MachineController::class, 'drop']);
            $router->post('/resetToken', [MachineController::class, 'resetToken']);
            $router->get('/getToken', [MachineController::class, 'getToken']);
            $router->get('/installCommand', [MachineController::class, 'installCommand']);
            $router->get('/nodes', [MachineController::class, 'nodes']);
            $router->get('/history', [MachineController::class, 'history']);
        });
    }
}
