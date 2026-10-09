<?php

use App\Http\Controllers\Txapi\AccountController;
use App\Http\Controllers\Txapi\AuthController;
use App\Http\Controllers\Txapi\ContentController;
use App\Http\Controllers\Txapi\TicketController;
use App\Http\Controllers\Txapi\PublicController;
use Illuminate\Support\Facades\Route;

// Only TXBoard native endpoints live here. No legacy controllers, no
// BFF/Agent/Node/webhook wildcard proxying and no guessed Gateway behavior.
Route::middleware('throttle:10,1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/register', [AuthController::class, 'register']);
});

Route::middleware('throttle:60,1')->group(function () {
    Route::get('public/config', [PublicController::class, 'config']);
    Route::get('plans', [PublicController::class, 'plans']);
});

Route::middleware(['txapi.user', 'throttle:120,1'])->group(function () {
    Route::get('plans/{planId}', [PublicController::class, 'plan'])->whereNumber('planId');
    Route::get('me', [AccountController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/sessions', [AuthController::class, 'sessions']);
    Route::delete('auth/sessions/{sessionId}', [AuthController::class, 'revoke'])->whereNumber('sessionId');
    Route::post('auth/password', [AuthController::class, 'password']);
    Route::get('notices', [ContentController::class, 'notices']);
    Route::get('knowledge', [ContentController::class, 'knowledge']);
    Route::get('knowledge/categories', [ContentController::class, 'categories']);
    Route::get('knowledge/{articleId}', [ContentController::class, 'article'])->whereNumber('articleId');
    Route::get('tickets', [TicketController::class, 'index']);
    Route::post('tickets', [TicketController::class, 'store']);
    Route::get('tickets/{ticketId}', [TicketController::class, 'show'])->whereNumber('ticketId');
    Route::post('tickets/{ticketId}/messages', [TicketController::class, 'reply'])->whereNumber('ticketId');
    Route::post('tickets/{ticketId}/close', [TicketController::class, 'close'])->whereNumber('ticketId');
    Route::get('orders', [AccountController::class, 'orders']);
    Route::get('orders/{tradeNo}', [AccountController::class, 'order']);
});
