<?php

namespace App\Http\Routes\V2\Admin;

use App\Http\Controllers\V2\Admin\Server\MachineController;
use Illuminate\Contracts\Routing\Registrar;

class ServerRoute
{
    public function map(Registrar $router): void
    {
        $router->group(['prefix' => 'server/machine'], function (Registrar $router): void {
            $router->get('/fetch', [MachineController::class, 'fetch']);
            $router->post('/save', [MachineController::class, 'save']);
            $router->post('/drop', [MachineController::class, 'drop']);
            $router->post('/resetToken', [MachineController::class, 'resetToken']);
            $router->get('/getToken', [MachineController::class, 'getToken']);
            $router->get('/installCommand', [MachineController::class, 'installCommand']);
            $router->get('/nodes', [MachineController::class, 'nodes']);
            $router->get('/history', [MachineController::class, 'history']);
            $router->post('/runtime/update', [MachineController::class, 'runtimeUpdate']);
        });
    }
}
