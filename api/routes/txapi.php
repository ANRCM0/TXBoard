<?php

use App\Http\Controllers\Txapi\Admin\AuditLogController;
use App\Http\Controllers\Txapi\Admin\CommerceReadController;
use App\Http\Controllers\Txapi\Admin\TicketAdminController;
use App\Http\Controllers\Txapi\Admin\UserReadController;
use App\Http\Controllers\Txapi\Admin\AccountMutationController;
use App\Http\Controllers\Txapi\Admin\UserEditorController;
use App\Http\Controllers\Txapi\Admin\PlanMutationController;
use App\Http\Controllers\Txapi\Admin\ContentAdminController;
use App\Http\Controllers\Txapi\Admin\OrderAdminController;
use App\Http\Controllers\Txapi\Admin\OrderOperationsAdminController;
use App\Http\Controllers\Txapi\Admin\UserMailAdminController;
use App\Http\Controllers\Txapi\Admin\ModuleAdminController;
use App\Http\Controllers\Txapi\Admin\TrafficResetAdminController;
use App\Http\Controllers\Txapi\Admin\PaymentSafetyController;
use App\Http\Controllers\Txapi\Admin\QueueAdminController;
use App\Http\Controllers\Txapi\Admin\CouponAdminController;
use App\Http\Controllers\Txapi\Admin\GiftCardAdminController;
use App\Http\Controllers\Txapi\Admin\MailTemplateAdminController;
use App\Http\Controllers\Txapi\Admin\SettingsAdminController;
use App\Http\Controllers\Txapi\Admin\NetworkGroupAdminController;
use App\Http\Controllers\Txapi\Admin\NetworkRouteAdminController;
use App\Http\Controllers\Txapi\Admin\NetworkNodeAdminController;
use App\Http\Controllers\Txapi\Admin\NetworkNodeSecretController;
use App\Http\Controllers\Txapi\Admin\NetworkMachineAdminController;
use App\Http\Controllers\Txapi\Admin\ThemeAdminController;
use App\Http\Controllers\Txapi\Admin\PluginAdminController;
use App\Http\Controllers\Txapi\Admin\AgentAdminController;
use App\Http\Controllers\Txapi\Admin\AnalyticsAdminController;
use App\Http\Controllers\Txapi\Admin\AgentSupportAdminController;
use App\Http\Controllers\Txapi\Admin\PaymentManagementController;
use App\Http\Controllers\Txapi\AccountController;
use App\Http\Controllers\Txapi\ServerController;
use App\Http\Controllers\Txapi\TrafficController;
use App\Http\Controllers\Txapi\NodeProtocolController;
use App\Http\Middleware\TxNodeAuth;
use App\Http\Controllers\Txapi\BillingController;
use App\Http\Controllers\Txapi\WalletRechargeController;
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
        // Native control-plane node management; no legacy V2 proxy.
        // Native machine admin — secret-bearing actions are authenticated POST only.
        // Native extension management; package uploads run archive validators
        // in the corresponding domain service, never directly in HTTP layer.
        Route::get('themes', [ThemeAdminController::class, 'index']);
        Route::post('themes/upload', [ThemeAdminController::class, 'upload']);
        Route::get('themes/{name}/config', [ThemeAdminController::class, 'config']);
        Route::put('themes/{name}/config', [ThemeAdminController::class, 'saveConfig']);
        Route::delete('themes/{name}', [ThemeAdminController::class, 'delete']);

        // Agent management is admin-only. The separate Agent bearer API is
        // a different execution boundary and is not proxied by this namespace.
        // Dashboard, ranking and accounting reads share the audited native
        // admin boundary and enforce server-side reporting bounds.
        // Native Module Registry: inventory and explicit lifecycle actions.
        Route::get('modules', [ModuleAdminController::class, 'index']);
        Route::get('modules/{id}/operations', [ModuleAdminController::class, 'operations']);
        Route::post('modules/{id}/operations/{operation}', [ModuleAdminController::class, 'execute']);
        Route::get('modules/{id}', [ModuleAdminController::class, 'show']);

        Route::get('analytics/dashboard', [AnalyticsAdminController::class, 'dashboard']);
        Route::get('analytics/overview', [AnalyticsAdminController::class, 'overview']);
        Route::get('analytics/orders/chart', [AnalyticsAdminController::class, 'orders']);
        Route::get('analytics/traffic/rank', [AnalyticsAdminController::class, 'trafficRank']);
        Route::get('analytics/rankings', [AnalyticsAdminController::class, 'ranking']);
        Route::get('analytics/users/{id}/traffic', [AnalyticsAdminController::class, 'userTraffic'])
            ->whereNumber('id');
        Route::get('analytics/records', [AnalyticsAdminController::class, 'records']);
        Route::get('analytics/nodes/rank/{period}', [AnalyticsAdminController::class, 'serverRank'])
            ->where('period', 'today|yesterday');

        Route::get('agents/abilities', [AgentAdminController::class, 'abilities']);
        Route::get('agents/tokens', [AgentAdminController::class, 'tokens']);
        Route::post('agents/tokens', [AgentAdminController::class, 'createToken']);
        Route::delete('agents/tokens/{id}', [AgentAdminController::class, 'revokeToken'])->whereNumber('id');
        Route::get('agents/fleet/health', [AgentAdminController::class, 'fleetHealth']);
        Route::get('agents/inspections', [AgentAdminController::class, 'inspections']);
        Route::post('agents/inspections', [AgentAdminController::class, 'runInspection']);
        Route::get('agents/nodes/{nodeId}/timeline', [AgentAdminController::class, 'nodeTimeline'])
            ->whereNumber('nodeId');
        Route::get('agents/actions', [AgentAdminController::class, 'actions']);
        Route::post('agents/actions/approve', [AgentAdminController::class, 'approveAction']);
        Route::post('agents/actions/reject', [AgentAdminController::class, 'rejectAction']);
        Route::get('agents/support/reply-requests', [AgentSupportAdminController::class, 'replies']);
        Route::post('agents/support/reply-requests/approve', [AgentSupportAdminController::class, 'approve']);
        Route::post('agents/support/reply-requests/reject', [AgentSupportAdminController::class, 'reject']);

        Route::get('plugins/types', [PluginAdminController::class, 'types']);
        Route::get('plugins', [PluginAdminController::class, 'index']);
        Route::post('plugins/upload', [PluginAdminController::class, 'upload']);
        Route::get('plugins/{code}/config', [PluginAdminController::class, 'config']);
        Route::put('plugins/{code}/config', [PluginAdminController::class, 'saveConfig']);
        Route::post('plugins/{code}/actions/{action}', [PluginAdminController::class, 'action']);
        Route::delete('plugins/{code}', [PluginAdminController::class, 'delete']);

        Route::get('network-machines', [NetworkMachineAdminController::class, 'index']);
        Route::post('network-machines', [NetworkMachineAdminController::class, 'create']);
        Route::put('network-machines/{id}', [NetworkMachineAdminController::class, 'update'])->whereNumber('id');
        Route::post('network-machines/{id}/credentials', [NetworkMachineAdminController::class, 'credentials'])
            ->whereNumber('id');
        Route::post('network-machines/{id}/token/rotate', [NetworkMachineAdminController::class, 'rotateToken'])
            ->whereNumber('id');
        Route::get('network-machines/{id}/nodes', [NetworkMachineAdminController::class, 'nodes'])->whereNumber('id');
        Route::get('network-machines/{id}/history', [NetworkMachineAdminController::class, 'history'])->whereNumber('id');
        Route::post('network-machines/{id}/runtime/update', [NetworkMachineAdminController::class, 'updateRuntime'])
            ->whereNumber('id');
        Route::delete('network-machines/{id}', [NetworkMachineAdminController::class, 'delete'])->whereNumber('id');

        Route::get('network-nodes', [NetworkNodeAdminController::class, 'index']);
        Route::get('network-nodes/protocols', [NetworkNodeAdminController::class, 'protocols']);
        Route::post('network-nodes/secrets', [NetworkNodeSecretController::class, 'generate'])
            ->middleware('throttle:10,1');
        Route::put('network-nodes/sort', [NetworkNodeAdminController::class, 'sort']);
        Route::patch('network-nodes/batch', [NetworkNodeAdminController::class, 'batchUpdate']);
        Route::post('network-nodes/batch-delete', [NetworkNodeAdminController::class, 'batchDelete']);
        Route::post('network-nodes/batch-traffic-reset', [NetworkNodeAdminController::class, 'batchResetTraffic']);
        Route::post('network-nodes', [NetworkNodeAdminController::class, 'save']);
        Route::put('network-nodes/{id}', [NetworkNodeAdminController::class, 'replace'])->whereNumber('id');
        Route::patch('network-nodes/{id}', [NetworkNodeAdminController::class, 'patch'])->whereNumber('id');
        Route::post('network-nodes/{id}/copy', [NetworkNodeAdminController::class, 'copy'])->whereNumber('id');
        Route::post('network-nodes/{id}/traffic-reset', [NetworkNodeAdminController::class, 'resetTraffic'])->whereNumber('id');
        Route::delete('network-nodes/{id}', [NetworkNodeAdminController::class, 'delete'])->whereNumber('id');

        Route::get('network-groups', [NetworkGroupAdminController::class, 'index']);
        Route::post('network-groups', [NetworkGroupAdminController::class, 'save']);
        Route::delete('network-groups/{id}', [NetworkGroupAdminController::class, 'delete'])->whereNumber('id');
        Route::get('network-routes', [NetworkRouteAdminController::class, 'index']);
        Route::post('network-routes', [NetworkRouteAdminController::class, 'save']);
        Route::put('network-routes/sort', [NetworkRouteAdminController::class, 'sort']);
        Route::post('network-routes/simulate', [NetworkRouteAdminController::class, 'simulate']);
        Route::delete('network-routes/{id}', [NetworkRouteAdminController::class, 'delete'])->whereNumber('id');
        Route::get('settings', [SettingsAdminController::class, 'index']);
        Route::get('settings/{group}', [SettingsAdminController::class, 'group']);
        Route::post('settings', [SettingsAdminController::class, 'save']);
        Route::post('settings/telegram/webhook', [SettingsAdminController::class, 'setTelegramWebhook'])
            ->middleware('throttle:3,1');
        Route::get('mail-templates', [MailTemplateAdminController::class, 'index']);
        Route::get('mail-templates/{name}', [MailTemplateAdminController::class, 'show']);
        Route::put('mail-templates/{name}', [MailTemplateAdminController::class, 'save']);
        Route::delete('mail-templates/{name}', [MailTemplateAdminController::class, 'reset']);
        Route::post('mail-templates/{name}/test', [MailTemplateAdminController::class, 'test']);
        Route::get('gift-cards/types', [GiftCardAdminController::class, 'types']);
        Route::get('gift-cards/templates', [GiftCardAdminController::class, 'templates']);
        Route::post('gift-cards/templates', [GiftCardAdminController::class, 'createTemplate']);
        Route::put('gift-cards/templates/{id}', [GiftCardAdminController::class, 'updateTemplate'])->whereNumber('id');
        Route::delete('gift-cards/templates/{id}', [GiftCardAdminController::class, 'deleteTemplate'])->whereNumber('id');
        Route::post('gift-cards/codes/batches', [GiftCardAdminController::class, 'issue']);
        Route::patch('gift-cards/codes/{id}/toggle', [GiftCardAdminController::class, 'toggle'])->whereNumber('id');
        Route::patch('gift-cards/codes/{id}', [GiftCardAdminController::class, 'editCode'])->whereNumber('id');
        Route::get('gift-cards/codes/export', [GiftCardAdminController::class, 'exportCodes']);
        Route::get('gift-cards/codes', [GiftCardAdminController::class, 'codes']);
        Route::get('gift-cards/statistics', [GiftCardAdminController::class, 'statistics']);
        Route::get('gift-cards/usages', [GiftCardAdminController::class, 'usages']);
        Route::delete('gift-cards/codes/{id}', [GiftCardAdminController::class, 'deleteCode'])->whereNumber('id');
        Route::get('coupons', [CouponAdminController::class, 'index']);
        Route::post('coupons', [CouponAdminController::class, 'save']);
        Route::post('coupons/export', [CouponAdminController::class, 'generateCsv']);
        Route::put('coupons/{id}', [CouponAdminController::class, 'save'])->whereNumber('id');
        Route::patch('coupons/{id}/toggle', [CouponAdminController::class, 'toggle'])->whereNumber('id');
        Route::delete('coupons/{id}', [CouponAdminController::class, 'delete'])->whereNumber('id');
        Route::get('queue/snapshot', [QueueAdminController::class, 'snapshot']);
        Route::get('queue/failures', [QueueAdminController::class, 'failures']);
        Route::get('queue/failures/{id}', [QueueAdminController::class, 'failure'])->whereNumber('id');
        Route::get('payment-methods', [PaymentManagementController::class, 'index']);
        Route::get('payment-methods/providers', [PaymentManagementController::class, 'providers']);
        Route::post('payment-methods/form', [PaymentManagementController::class, 'form']);
        Route::post('payment-methods', [PaymentManagementController::class, 'save']);
        Route::put('payment-methods/sort', [PaymentManagementController::class, 'sort']);
        Route::put('payment-methods/{id}', [PaymentManagementController::class, 'save'])->whereNumber('id');
        Route::patch('payment-methods/{id}/toggle', [PaymentManagementController::class, 'toggle'])->whereNumber('id');
        Route::post('payment-methods/{id}/delete', [PaymentSafetyController::class, 'delete'])->whereNumber('id');
        Route::get('traffic-resets', [TrafficResetAdminController::class, 'index']);
        Route::get('traffic-resets/stats', [TrafficResetAdminController::class, 'stats']);
        Route::get('traffic-resets/users/{id}', [TrafficResetAdminController::class, 'show'])->whereNumber('id');
        Route::post('traffic-resets/users/{id}/reset', [TrafficResetAdminController::class, 'reset'])->whereNumber('id');
        Route::get('plans', [CommerceReadController::class, 'plans']);
        Route::get('orders', [CommerceReadController::class, 'orders']);
        Route::post('orders/assign', [OrderOperationsAdminController::class, 'assign']);
        Route::post('orders/{tradeNo}/commission-review', [OrderOperationsAdminController::class, 'commission']);
        Route::get('orders/{id}/detail', [OrderAdminController::class, 'detail'])->whereNumber('id');
        Route::post('orders/{tradeNo}/paid', [OrderAdminController::class, 'paid']);
        Route::post('orders/{tradeNo}/cancel', [OrderAdminController::class, 'cancel']);
        Route::get('tickets', [TicketAdminController::class, 'index']);
        Route::get('tickets/{id}', [TicketAdminController::class, 'show'])->whereNumber('id');
        Route::post('tickets/{id}/reply', [TicketAdminController::class, 'reply'])->whereNumber('id');
        Route::post('tickets/{id}/close', [TicketAdminController::class, 'close'])->whereNumber('id');
        Route::get('users', [UserReadController::class, 'index']);
        Route::post('users', [UserEditorController::class, 'create']);
        Route::post('users/{id}/update', [UserEditorController::class, 'update'])->whereNumber('id');
        Route::get('users/{id}', [UserReadController::class, 'show'])->whereNumber('id');
        Route::get('users/{id}/subscription-link', [UserReadController::class, 'subscriptionLink'])->whereNumber('id');
        Route::post('users/{id}/subscription-credentials/rotate', [AccountMutationController::class, 'rotate'])->whereNumber('id');
        Route::post('users/{id}/delete', [AccountMutationController::class, 'delete'])->whereNumber('id');
        Route::post('users/ban', [AccountMutationController::class, 'ban']);
        Route::post('users/mail', [UserMailAdminController::class, 'send']);
        // Existing plan mutations retain POST; RequestLog now audits POST, PUT, PATCH and DELETE.
        Route::post('plans', [PlanMutationController::class, 'save']);
        Route::post('plans/sort', [PlanMutationController::class, 'sort']);
        Route::post('plans/{id}/flags', [PlanMutationController::class, 'flags'])->whereNumber('id');
        Route::post('plans/{id}/delete', [PlanMutationController::class, 'delete'])->whereNumber('id');
        Route::get('content/notices', [ContentAdminController::class, 'notices']);
        Route::post('content/notices', [ContentAdminController::class, 'saveNotice']);
        Route::put('content/notices/sort', [ContentAdminController::class, 'sortNotices']);
        Route::get('content/notices/{id}', [ContentAdminController::class, 'notice'])->whereNumber('id');
        Route::put('content/notices/{id}', [ContentAdminController::class, 'saveNotice'])->whereNumber('id');
        Route::patch('content/notices/{id}/visibility', [ContentAdminController::class, 'showNotice'])->whereNumber('id');
        Route::delete('content/notices/{id}', [ContentAdminController::class, 'deleteNotice'])->whereNumber('id');
        Route::get('content/knowledge', [ContentAdminController::class, 'knowledge']);
        Route::post('content/knowledge', [ContentAdminController::class, 'saveArticle']);
        Route::get('content/knowledge/categories', [ContentAdminController::class, 'categories']);
        Route::put('content/knowledge/sort', [ContentAdminController::class, 'sortArticles']);
        Route::get('content/knowledge/{id}', [ContentAdminController::class, 'article'])->whereNumber('id');
        Route::put('content/knowledge/{id}', [ContentAdminController::class, 'saveArticle'])->whereNumber('id');
        Route::patch('content/knowledge/{id}/visibility', [ContentAdminController::class, 'showArticle'])->whereNumber('id');
        Route::delete('content/knowledge/{id}', [ContentAdminController::class, 'deleteArticle'])->whereNumber('id');
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
    Route::get('billing/recharge-payment-methods', [WalletRechargeController::class, 'methods']);
    Route::get('billing/recharges', [WalletRechargeController::class, 'index']);
    Route::post('billing/recharges', [WalletRechargeController::class, 'store'])->middleware('throttle:10,1');
    Route::get('billing/recharges/{tradeNo}', [WalletRechargeController::class, 'show']);
    Route::post('billing/recharges/{tradeNo}/checkout', [WalletRechargeController::class, 'checkout'])->middleware('throttle:10,1');
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
