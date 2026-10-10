<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PlanService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OrderOperationsAdminController
{
    /**
     * Manual allocation creates a PENDING order; it must never credit a wallet,
     * bypass the shared paid() state machine or silently settle an order.
     */
    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'plan_id' => ['required', 'integer', 'min:1', \Illuminate\Validation\Rule::exists('tx_plan', 'id')],
            'period' => ['required', 'in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price,onetime_price,reset_price'],
            'total_amount' => ['required', 'integer', 'min:0', 'max:2147483647'],
        ]);

        $result = DB::transaction(function () use ($data): ?string {
            $user = User::query()->where('email', $data['email'])
                ->lockForUpdate()->first();
            if (!$user) return null;

            // Lock the account before checking for an open order. Competing
            // manual assignments serialize on this same account record.
            if ((new UserService())->isNotCompleteOrderByUserId((int) $user->id)) {
                return '';
            }

            $plan = Plan::query()->find((int) $data['plan_id']);
            if (!$plan) return null;

            $order = new Order();
            $order->user_id = $user->id;
            $order->plan_id = $plan->id;
            $order->period = PlanService::getPeriodKey((string) $data['period']);
            $order->trade_no = Helper::guid();
            $order->total_amount = (int) $data['total_amount'];
            if ($order->period === Plan::PERIOD_RESET_TRAFFIC) {
                $order->type = Order::TYPE_RESET_TRAFFIC;
            } elseif ($user->plan_id !== null && $order->plan_id !== $user->plan_id) {
                $order->type = Order::TYPE_UPGRADE;
            } elseif ($user->expired_at > time() && $order->plan_id === $user->plan_id) {
                $order->type = Order::TYPE_RENEWAL;
            } else {
                $order->type = Order::TYPE_NEW_PURCHASE;
            }
            (new OrderService($order))->setInvite($user);
            $order->save();
            return (string) $order->trade_no;
        }, 3);

        if ($result === null) {
            return TxapiResponse::error($request, 'ORDER_ASSIGN_TARGET_MISSING',
                'User or plan not found', 404);
        }
        if ($result === '') {
            return TxapiResponse::error($request, 'ORDER_ALREADY_PENDING',
                'User already has an unfinished order', 409);
        }

        return TxapiResponse::success($request, ['trade_no' => $result], [], 201)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * This changes only the administrative commission REVIEW state.
     * Settlement/withdrawal history is immutable here.
     */
    public function commission(Request $request): JsonResponse
    {
        $data = $request->validate([
            'commission_status' => ['required', 'integer', 'in:0,1,3'],
        ]);
        $tradeNo = (string) $request->route('tradeNo');
        $result = DB::transaction(function () use ($tradeNo, $data): string {
            $order = Order::query()->where('trade_no', $tradeNo)
                ->lockForUpdate()->first();
            if (!$order) return 'missing';
            if (!$order->invite_user_id ||
                (int) $order->commission_balance <= 0 ||
                in_array((int) $order->status, [Order::STATUS_PENDING, Order::STATUS_CANCELLED], true) ||
                (int) $order->commission_status === 2 ||
                $order->commission_log()->exists()) {
                return 'conflict';
            }
            $order->commission_status = (int) $data['commission_status'];
            $order->save();
            return 'updated';
        }, 3);
        if ($result === 'missing') {
            return TxapiResponse::error($request, 'ORDER_NOT_FOUND', 'Order not found', 404);
        }
        if ($result !== 'updated') {
            return TxapiResponse::error($request, 'COMMISSION_REVIEW_CONFLICT',
                'Commission cannot be changed for this order', 409);
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }
}
