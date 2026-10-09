<?php
namespace App\Http\Routes\V1;

use App\Http\Controllers\V1\User\CommController;
use App\Http\Controllers\V1\User\GiftCardController;
use App\Http\Controllers\V1\User\InviteController;
use App\Http\Controllers\V1\User\TelegramController;
use App\Http\Controllers\V1\User\TicketController;
use App\Http\Controllers\V1\User\UserController;
use Illuminate\Contracts\Routing\Registrar;

class UserRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'user',
            'middleware' => 'user'
        ], function ($router) {
            // User
            $router->get('/info', [UserController::class, 'info']);
            $router->post('/transfer', [UserController::class, 'transfer']);
            // Order
            // Plan
            // Invite
            $router->get('/invite/save', [InviteController::class, 'save']);
            $router->get('/invite/fetch', [InviteController::class, 'fetch']);
            // Notice
            // Ticket
            $router->post('/ticket/withdraw', [TicketController::class, 'withdraw']);
            // Server
            // Coupon
            // Gift Card
            $router->post('/gift-card/check', [GiftCardController::class, 'check']);
            $router->post('/gift-card/redeem', [GiftCardController::class, 'redeem']);
            $router->get('/gift-card/history', [GiftCardController::class, 'history']);
            $router->get('/gift-card/detail', [GiftCardController::class, 'detail']);
            $router->get('/gift-card/types', [GiftCardController::class, 'types']);
            // Telegram
            $router->get('/telegram/getBotInfo', [TelegramController::class, 'getBotInfo']);
            // Comm
            $router->get('/comm/config', [CommController::class, 'config']);
            $router->Post('/comm/getStripePublicKey', [CommController::class, 'getStripePublicKey']);
            // Knowledge
            // Stat
        });
    }
}
