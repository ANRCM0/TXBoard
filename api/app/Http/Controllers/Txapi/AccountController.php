<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Utils\Helper;

final class AccountController
{
    public function me(Request $request): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();
        return TxapiResponse::success($request, [
            'id' => (int) $user->id,
            'email' => (string) $user->email,
            'plan_id' => $user->plan_id === null ? null : (int) $user->plan_id,
            'uuid' => (string) $user->uuid,
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

    public function subscription(Request $request, UserService $service): JsonResponse
    {
        // Only the authenticated subscriber may see their private subscription
        // URL. Never expose the underlying raw token in the response.
        $user = User::query()->findOrFail(Auth::guard('sanctum')->id());
        $plan = $user->plan_id ? Plan::query()->find($user->plan_id) : null;
        if ($user->plan_id && $plan === null) {
            return TxapiResponse::error($request, 'PLAN_UNAVAILABLE',
                'Subscription plan unavailable', 409);
        }
        return TxapiResponse::success($request, [
            'subscribe_url' => Helper::getSubscribeUrl((string) $user->token),
            'reset_day' => $service->getResetDay($user),
            'plan' => $plan ? [
                'id' => (int) $plan->id,
                'name' => (string) $plan->name,
                'traffic_limit_bytes' => (int) $plan->transfer_enable * 1073741824,
            ] : null,
            'upload_bytes' => (int) ($user->u ?? 0),
            'download_bytes' => (int) ($user->d ?? 0),
            'traffic_limit_bytes' => (int) ($user->transfer_enable ?? 0),
            'device_limit' => $user->device_limit === null ? null : (int) $user->device_limit,
            'speed_limit_mbps' => $user->speed_limit === null ? null : (float) $user->speed_limit,
            'expired_at' => $user->expired_at
                ? Carbon::createFromTimestampUTC((int) $user->expired_at)->toIso8601String() : null,
            'next_reset_at' => $user->next_reset_at
                ? Carbon::createFromTimestampUTC((int) $user->next_reset_at)->toIso8601String() : null,
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

    public function rotateSubscriptionCredentials(Request $request): JsonResponse
    {
        // Mutates a private subscription secret; never expose this as GET.
        // Serialize concurrent rotations and preserve existing Sanctum sessions.
        $newUrl = DB::transaction(static function (): string {
            $user = User::query()->whereKey(Auth::guard('sanctum')->id())
                ->lockForUpdate()->firstOrFail();
            $user->uuid = Helper::guid(true);
            $user->token = Helper::guid();
            $user->saveOrFail();
            return Helper::getSubscribeUrl($user->token);
        });
        return TxapiResponse::success($request, ['subscribe_url' => $newUrl]);
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
        foreach ($values as $key => $value) {
            $user->$key = (bool) $value;
        }
        $user->saveOrFail();
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

        $userId = (int) Auth::guard('sanctum')->id();
        $query = Order::query()->with('plan:id,name')->where('user_id', $userId);
        if (array_key_exists('status', $input)) {
            $query->where('status', $input['status']);
        }
        $perPage = (int) ($input['per_page'] ?? 20);
        $page = (int) ($input['page'] ?? 1);
        $result = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage, ['id', 'trade_no', 'plan_id', 'period', 'type',
                'status', 'total_amount', 'paid_at', 'created_at'], 'page', $page);

        return TxapiResponse::success($request,
            $result->getCollection()->map(static fn (Order $order): array => self::toOrder($order))->all(),
            [
                'page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
            ]
        );
    }

    public function order(Request $request, string $tradeNo): JsonResponse
    {
        $order = Order::query()->with('plan:id,name')->where('user_id', Auth::guard('sanctum')->id())
            ->where('trade_no', $tradeNo)->first();
        if ($order === null) {
            abort(404);
        }
        return TxapiResponse::success($request, self::toOrder($order));
    }

    public function orderDetail(Request $request, string $tradeNo): JsonResponse
    {
        // Dedicated detail projection. The lightweight list/status DTO must
        // not expand to include payment, wallet or private account data.
        $order = Order::query()->with('plan:id,name,transfer_enable')
            ->where('user_id', Auth::guard('sanctum')->id())
            ->where('trade_no', $tradeNo)->first();
        if ($order === null || $order->plan === null) {
            abort(404);
        }
        $data = self::toOrder($order);
        $data['plan'] = [
            'id' => (int) $order->plan->id,
            'name' => (string) $order->plan->name,
            'traffic_limit_bytes' => (int) $order->plan->transfer_enable * 1073741824,
        ];
        $data['payment_id'] = $order->payment_id === null ? null : (int) $order->payment_id;
        $data['balance_amount_minor'] = (int) ($order->balance_amount ?? 0);
        $data['discount_amount_minor'] = (int) ($order->discount_amount ?? 0);
        return TxapiResponse::success($request, $data);
    }

    private static function toOrder(Order $order): array
    {
        return [
            'id' => (int) $order->id,
            'trade_no' => (string) $order->trade_no,
            'plan_id' => (int) $order->plan_id,
            'period' => (string) $order->period,
            'type' => (int) $order->type,
            'status' => (int) $order->status,
            'amount_minor' => (int) $order->total_amount,
            'plan' => $order->plan ? ['id' => (int) $order->plan->id, 'name' => (string) $order->plan->name] : null,
            'paid_at' => $order->paid_at ? Carbon::createFromTimestampUTC((int) $order->paid_at)->toIso8601String() : null,
            'created_at' => Carbon::createFromTimestampUTC((int) $order->created_at)
                ->toIso8601String(),
        ];
    }
}
