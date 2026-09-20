<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V1\Guest\CommController;
use Illuminate\Contracts\Routing\Registrar;

class GuestRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'guest'
        ], function ($router) {
            // Comm
            $router->get('/comm/config', [CommController::class, 'config']);
        });
    }
}
