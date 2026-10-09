<?php

use App\Http\Controllers\Txapi\AccountController;
use App\Http\Controllers\Txapi\TrafficController;
use App\Http\Controllers\Txapi\NodeProtocolController;
use App\Http\Middleware\TxNodeAuth;
use App\Http\Controllers\Txapi\BillingController;
use App\Http\Controllers\Txapi\PaymentWebhookController;
use App\Http\Controllers\Txapi\AuthController;
use App\Http\Controllers\Txapi\ContentController;
use App\Http\Controllers\Txapi\TicketController;
use App\Http\Controllers\Txapi\PublicController;
use Illuminate\Support\Facades\Route;

// Versioned TX-Node control plane; scoped bearer credentials, separate from user
// auth. Never expose node credentials in URLs. Legacy V1/V2 stays live.
Route::prefix('node/v1')->middleware(TxNodeAuth::class)->group(function () {
    Route::post('handshake', [NodeProtocolController::class, 'handshake']);
    Route::get('config', [NodeProtocolController::class, 'config']);
    Route::get('users', [NodeProtocolController::class, 'users']);
    Route::post('report', [NodeProtocolController::class, 'report']);
    Route::get('machine/nodes', [NodeProtocolController::class, 'machineNodes']);
    Route::post('machine/status', [NodeProtocolController::class, 'machineStatus']);
});

// Native provider callback is intentionally not JSON-wrapped. It goes through
// the identical signed, locked processor as the legacy V1 route.
Route::match(['get', 'post'], 'payment/webhook/{method}/{uuid}',
    [PaymentWebhookController::class, 'notify']);

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
    Route::get('traffic/logs', [TrafficController::class, 'index']);
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
    Route::get('billing/wallet', [BillingController::class, 'wallet']);
    Route::get('billing/commissions', [BillingController::class, 'commissions']);
    Route::get('billing/payment-methods', [BillingController::class, 'methods']);
    Route::post('billing/coupons/check', [BillingController::class, 'checkCoupon']);
    Route::post('orders', [BillingController::class, 'createOrder']);
    Route::post('orders/{tradeNo}/cancel', [BillingController::class, 'cancelOrder']);
    Route::post('orders/{tradeNo}/checkout', [BillingController::class, 'checkout']);
    Route::get('orders', [AccountController::class, 'orders']);
    Route::get('orders/{tradeNo}', [AccountController::class, 'order']);
});
