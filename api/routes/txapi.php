<?php

use App\Http\Controllers\Txapi\Admin\AuditLogController;
use App\Http\Controllers\Txapi\Admin\CommerceReadController;
use App\Http\Controllers\Txapi\Admin\TicketAdminController;
use App\Http\Controllers\Txapi\Admin\PlanMutationController;
use App\Http\Controllers\Txapi\AccountController;
use App\Http\Controllers\Txapi\ServerController;
use App\Http\Controllers\Txapi\TrafficController;
use App\Http\Controllers\Txapi\NodeProtocolController;
use App\Http\Middleware\TxNodeAuth;
use App\Http\Controllers\Txapi\BillingController;
use App\Http\Controllers\Txapi\InviteController;
use App\Http\Controllers\Txapi\CommissionController;
use App\Http\Controllers\Txapi\GiftCardController;
use App\Http\Controllers\Txapi\StripeConfigController;
use App\Http\Controllers\Txapi\InvitePageViewController;
use App\Http\Controllers\Txapi\WithdrawalController;
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
// First native React Admin read domain. The dynamic admin path is validated
// before authorization, preserving the legacy AdminPath 404 invariant.
// Do not open this namespace to ordinary user, node or agent bearer tokens.
Route::prefix('admin/{admin_path}')
    ->middleware(['admin.path', 'admin', 'log', 'throttle:120,1'])
    ->group(function () {
        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::get('plans', [CommerceReadController::class, 'plans']);
        Route::get('orders', [CommerceReadController::class, 'orders']);
        // POST is intentionally used for all administrator mutations until the
        // RequestLog audit middleware records all unsafe HTTP methods.
        Route::post('plans', [PlanMutationController::class, 'save']);
        Route::post('plans/sort', [PlanMutationController::class, 'sort']);
        Route::post('plans/{id}/flags', [PlanMutationController::class, 'flags'])->whereNumber('id');
        Route::post('plans/{id}/delete', [PlanMutationController::class, 'delete'])->whereNumber('id');
        Route::get('tickets', [TicketAdminController::class, 'index']);
        Route::get('tickets/{id}', [TicketAdminController::class, 'show'])->whereNumber('id');
        Route::post('tickets/{id}/reply', [TicketAdminController::class, 'reply'])->whereNumber('id');
        Route::post('tickets/{id}/close', [TicketAdminController::class, 'close'])->whereNumber('id');
    });

Route::middleware('throttle:10,1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/register', [AuthController::class, 'register']);
    // Shared MailLinkService keeps V1 email links and native requests interoperable.
    Route::post('auth/mail-link', [AuthController::class, 'mailLink']);
    Route::post('auth/one-time-token', [AuthController::class, 'oneTimeToken']);
    Route::post('auth/email-code', [AuthController::class, 'sendEmailCode']);
    Route::post('auth/password/forgot', [AuthController::class, 'forgotPassword']);
});

Route::middleware('throttle:60,1')->group(function () {
    Route::get('public/config', [PublicController::class, 'config']);
    Route::get('public/site-config', [PublicController::class, 'siteConfig']);
    Route::get('plans', [PublicController::class, 'plans']);
    Route::post('public/invite-page-view', [InvitePageViewController::class, 'store'])->middleware('throttle:20,1');
});

Route::middleware(['txapi.user', 'throttle:120,1'])->group(function () {
    Route::get('plans/{planId}', [PublicController::class, 'plan'])->whereNumber('planId');
    Route::get('me', [AccountController::class, 'me']);
    Route::get('me/subscription', [AccountController::class, 'subscription']);
    Route::get('me/dashboard-stats', [AccountController::class, 'dashboardStats']);
    Route::get('me/site-config', [AccountController::class, 'userConfig']);
    Route::get('me/nodes', [ServerController::class, 'index']);
    Route::get('me/preferences', [AccountController::class, 'preferences']);
    Route::patch('me/preferences', [AccountController::class, 'updatePreferences']);
    Route::get('traffic/logs', [TrafficController::class, 'index']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/sessions', [AuthController::class, 'sessions']);
    Route::delete('auth/sessions/{sessionId}', [AuthController::class, 'revoke'])->whereNumber('sessionId');
    Route::post('auth/password', [AuthController::class, 'password']);
    // Sensitive self-service actions; legacy endpoints remain while callers migrate.
    Route::post('auth/quick-login', [AuthController::class, 'quickLogin'])
        ->middleware('throttle:5,1');
    Route::post('me/subscription-credentials/rotate', [AccountController::class, 'rotateSubscriptionCredentials'])
        ->middleware('throttle:3,1');
    Route::get('notices', [ContentController::class, 'notices']);
    Route::get('knowledge', [ContentController::class, 'knowledge']);
    Route::get('knowledge/categories', [ContentController::class, 'categories']);
    Route::get('knowledge/{articleId}', [ContentController::class, 'article'])->whereNumber('articleId');
    Route::get('tickets', [TicketController::class, 'index']);
    Route::post('tickets', [TicketController::class, 'store']);
    Route::get('tickets/{ticketId}', [TicketController::class, 'show'])->whereNumber('ticketId');
    Route::post('tickets/{ticketId}/messages', [TicketController::class, 'reply'])->whereNumber('ticketId');
    Route::post('tickets/{ticketId}/close', [TicketController::class, 'close'])->whereNumber('ticketId');
    Route::get('invites', [InviteController::class, 'index']);
    Route::post('invites', [InviteController::class, 'store'])->middleware('throttle:10,1');
    Route::post('billing/commission-transfer', [CommissionController::class, 'transfer'])->middleware('throttle:10,1');
    Route::post('gift-cards/check', [GiftCardController::class, 'check']);
    Route::post('gift-cards/redeem', [GiftCardController::class, 'redeem'])->middleware('throttle:5,1');
    Route::get('gift-cards/history', [GiftCardController::class, 'history']);
    Route::get('gift-cards/types', [GiftCardController::class, 'types']);
    Route::get('gift-cards/history/{id}', [GiftCardController::class, 'detail'])->whereNumber('id');
    Route::post('billing/stripe-public-key', [StripeConfigController::class, 'publicKey']);
    Route::post('billing/withdrawals', [WithdrawalController::class, 'store'])->middleware('throttle:5,1');
    Route::get('billing/wallet', [BillingController::class, 'wallet']);
    Route::get('billing/commissions', [BillingController::class, 'commissions']);
    Route::get('billing/payment-methods', [BillingController::class, 'methods']);
    Route::post('billing/coupons/check', [BillingController::class, 'checkCoupon']);
    Route::post('orders', [BillingController::class, 'createOrder']);
    Route::post('orders/{tradeNo}/cancel', [BillingController::class, 'cancelOrder']);
    Route::post('orders/{tradeNo}/checkout', [BillingController::class, 'checkout']);
    Route::get('orders', [AccountController::class, 'orders']);
    Route::get('orders/{tradeNo}/detail', [AccountController::class, 'orderDetail']);
    Route::get('orders/{tradeNo}', [AccountController::class, 'order']);
});
