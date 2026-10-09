<?php

namespace App\Http\Routes\V2\Admin;

use App\Http\Controllers\V2\Admin\ModuleController;
use Illuminate\Contracts\Routing\Registrar;

class ExtensionRoute
{
    public function map(Registrar $router): void
    {
        $router->group(['prefix' => 'module'], function (Registrar $router): void {
            $router->get('/', [ModuleController::class, 'index']);
            $router->get('/{id}/lifecycle', [ModuleController::class, 'lifecycle']);
            $router->post('/{id}/lifecycle/{operation}', [ModuleController::class, 'executeLifecycle']);
            $router->get('/{id}', [ModuleController::class, 'show']);
        });


    }
}
