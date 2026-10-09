<?php

namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\User\CommController;
use App\Http\Controllers\V1\User\KnowledgeController;
use App\Http\Controllers\V1\User\TelegramController;
use Illuminate\Contracts\Routing\Registrar;

/**
 * Temporary internal legacy bridges only.
 *
 * The official Vue user SPA consumes TXAPI for account, billing, orders,
 * invitations, gift cards and node listings. Old V1 route registrations for
 * these domains have no supported TXBoard-internal consumer and are retired.
 * The remaining three routes are still pinned by internal configuration and
 * knowledge tests (or the Telegram bot-info compatibility path).
 */
class UserRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'user',
            'middleware' => 'user',
        ], function ($router) {
            $router->get('/comm/config', [CommController::class, 'config']);
            $router->get('/knowledge/getCategory', [KnowledgeController::class, 'getCategory']);
            $router->get('/telegram/getBotInfo', [TelegramController::class, 'getBotInfo']);
        });
    }
}
