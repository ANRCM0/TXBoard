<?php

use App\Http\Controllers\Txapi\AccountController;
use App\Http\Controllers\Txapi\PublicController;
use Illuminate\Support\Facades\Route;

// Only TXBoard native endpoints live here. No legacy controllers, no
// BFF/Agent/Node/webhook wildcard proxying and no guessed Gateway behavior.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('public/config', [PublicController::class, 'config']);
    Route::get('plans', [PublicController::class, 'plans']);
});

Route::middleware(['txapi.user', 'throttle:120,1'])->group(function () {
    Route::get('me', [AccountController::class, 'me']);
    Route::get('orders', [AccountController::class, 'orders']);
    Route::get('orders/{tradeNo}', [AccountController::class, 'order']);
});
