<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Domains\Billing\Contracts\OrderListQuery;
use App\Domains\Billing\Contracts\OrderQueries;
use App\Domains\Identity\Contracts\AccountIdentity;
use App\Domains\Identity\Contracts\AccountQueries;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\SiteConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Domains\Identity\AccountSqlPhase as IdentityPhase;

final class AccountController
{
    public function me(Request $request): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();
        if (app(\App\Domains\Installation\InstallationAuthority::class)->inspect()['mode'] === 'native') {
            return TxapiResponse::success($request, app(AccountQueries::class)->account(new AccountIdentity(
                (int) $user->id, (string) $user->email, $user->telegram_id === null ? null : (int) $user->telegram_id,
            )));
        }
        return TxapiResponse::success($request, [
            'id' => (int) $user->id,
            'email' => (string) $user->email,
            'plan_id' => $user->plan_id === null ? null : (int) $user->plan_id,
            'balance_minor' => (int) $user->balance,
            'commission_balance_minor' => (int) $user->commission_balance,
            'expired_at' => $user->expired_at ? Carbon::createFromTimestampUTC((int) $user->expired_at)->toIso8601String() : null,
            'telegram_id' => $user->telegram_id === null ? null : (int) $user->telegram_id,
            'traffic' => [
                'upload_bytes' => (int) ($user->u ?? 0),
                'download_bytes' => (int) ($user->d ?? 0),
                'limit_bytes' => (int) ($user->transfer_enable ?? 0),
            ],
        ]);
    }

    public function subscription(Request $request): JsonResponse
    {
        return TxapiResponse::success($request,
            app(AccountQueries::class)->subscription((int) Auth::guard('sanctum')->id()) + [
                'delivery_endpoint' => '/txapi/me/subscription-deliveries',
            ]);
    }

    public function dashboardStats(Request $request): JsonResponse
    {
        $userId = (int) Auth::guard('sanctum')->id();
        return TxapiResponse::success($request, [
            'unpaid_orders' => Order::query()->where('user_id', $userId)
                ->where('status', 0)->count(),
            'open_tickets' => Ticket::query()->where('user_id', $userId)
                ->where('status', 0)->count(),
            'invited_users' => User::query()->where('invite_user_id', $userId)->count(),
        ]);
    }


    public function userConfig(Request $request, SiteConfigService $settings): JsonResponse
    {
        return TxapiResponse::success($request, $settings->user());
    }

    public function preferences(Request $request): JsonResponse
    {
        return TxapiResponse::success($request, self::preferencesDto(Auth::guard('sanctum')->user()));
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $values = $request->validate([
            'remind_expire' => ['sometimes', 'required', 'boolean'],
            'remind_traffic' => ['sometimes', 'required', 'boolean'],
        ]);
        if ($values === []) {
            return TxapiResponse::error($request, 'PREFERENCES_REQUIRED',
                'At least one preference must be supplied', 422);
        }
        $user = Auth::guard('sanctum')->user();
        // Explicit allowlist; cannot mutate balance, role or subscription tokens.
        app(IdentityPhase::class)->run(IdentityPhase::USER_ACCOUNT_PREFERENCES, function () use (&$user, $values): void {
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            foreach ($values as $key => $value) {
                $user->$key = (bool) $value;
            }
            $user->saveOrFail();
        });
        return TxapiResponse::success($request, self::preferencesDto($user));
    }

    private static function preferencesDto($user): array
    {
        return [
            'remind_expire' => (bool) $user->remind_expire,
            'remind_traffic' => (bool) $user->remind_traffic,
        ];
    }

    public function orders(Request $request): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'integer', 'in:0,1,2,3,4'],
        ]);

        $page = app(OrderQueries::class)->listing((int) Auth::guard('sanctum')->id(), new OrderListQuery(
            (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 20),
            array_key_exists('status', $input) ? (int) $input['status'] : null,
        ));
        return TxapiResponse::success($request, $page->items, $page->meta);
    }

    public function order(Request $request, string $tradeNo): JsonResponse
    {
        $order = app(OrderQueries::class)->find((int) Auth::guard('sanctum')->id(), $tradeNo);
        if ($order === null) abort(404);
        return TxapiResponse::success($request, $order);
    }

    public function orderDetail(Request $request, string $tradeNo): JsonResponse
    {
        $order = app(OrderQueries::class)->detail((int) Auth::guard('sanctum')->id(), $tradeNo);
        if ($order === null) abort(404);
        return TxapiResponse::success($request, $order);
    }
}
