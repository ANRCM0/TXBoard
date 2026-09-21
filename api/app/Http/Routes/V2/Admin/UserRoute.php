<?php

namespace App\Http\Routes\V2\Admin;

use App\Http\Controllers\V2\Admin\TicketController;
use App\Http\Controllers\V2\Admin\UserController;
use Illuminate\Contracts\Routing\Registrar;

class UserRoute
{
    public function map(Registrar $router): void
    {
        $router->group(['prefix' => 'user'], function (Registrar $router): void {
            $router->any('/fetch', [UserController::class, 'fetch']);
            $router->post('/update', [UserController::class, 'update']);
            $router->get('/getUserInfoById', [UserController::class, 'getUserInfoById']);
            $router->post('/generate', [UserController::class, 'generate']);
            $router->post('/dumpCSV', [UserController::class, 'dumpCSV']);
            $router->post('/sendMail', [UserController::class, 'sendMail']);
            $router->post('/ban', [UserController::class, 'ban']);
            $router->post('/resetSecret', [UserController::class, 'resetSecret']);
            $router->post('/setInviteUser', [UserController::class, 'setInviteUser']);
            $router->post('/destroy', [UserController::class, 'destroy']);
        });

        $router->group(['prefix' => 'ticket'], function (Registrar $router): void {
            $router->any('/fetch', [TicketController::class, 'fetch']);
            $router->post('/reply', [TicketController::class, 'reply']);
            $router->post('/close', [TicketController::class, 'close']);
        });
    }
}
