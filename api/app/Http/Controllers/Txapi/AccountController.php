<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;

final class AccountController
{
    public function me(Request $request): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();
        return TxapiResponse::success($request, [
            'id' => (int) $user->id,
            'email' => (string) $user->email,
            'plan_id' => $user->plan_id === null ? null : (int) $user->plan_id,
            'traffic' => [
                'upload_bytes' => (int) ($user->u ?? 0),
                'download_bytes' => (int) ($user->d ?? 0),
                'limit_bytes' => (int) ($user->transfer_enable ?? 0),
            ],
        ]);
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
