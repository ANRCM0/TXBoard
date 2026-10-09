<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderAdminController
{
    public function detail(Request $request): JsonResponse
    {
        $order = Order::query()->with([
            'user:id,email','plan:id,name','invite_user:id,email',
        ])->findOrFail((int) $request->route('id'));
        $surplus = [];
        if (is_array($order->surplus_order_ids) && count($order->surplus_order_ids)) {
            // Limit historical cross-order expansion and never return
            // unfiltered Eloquent order or user models.
            $surplus = Order::query()->whereIn('id', array_slice($order->surplus_order_ids, 0, 100))
                ->orderBy('id')->get(['id','trade_no','total_amount'])
                ->map(static fn (Order $o): array => [
                    'id' => (int) $o->id, 'trade_no' => (string) $o->trade_no,
                    'total_amount' => (int) $o->total_amount,
                ])->all();
        }
        return TxapiResponse::success($request, [
            'id' => (int) $order->id,
            'trade_no' => (string) $order->trade_no,
            'user_id' => (int) $order->user_id,
            'plan_id' => (int) $order->plan_id,
            'user' => $order->user ? [
                'id' => (int) $order->user->id, 'email' => (string) $order->user->email,
            ] : null,
            'plan' => $order->plan ? [
                'id' => (int) $order->plan->id, 'name' => (string) $order->plan->name,
            ] : null,
            'period' => PlanService::getLegacyPeriod((string) $order->period),
            'status' => (int) $order->status, 'type' => (int) $order->type,
            'total_amount' => (int) $order->total_amount,
            'handling_amount' => (int) ($order->handling_amount ?? 0),
            'balance_amount' => (int) ($order->balance_amount ?? 0),
            'discount_amount' => (int) ($order->discount_amount ?? 0),
            'callback_no' => $order->callback_no,
            'created_at' => (int) $order->created_at,
            'paid_at' => $order->paid_at === null ? null : (int) $order->paid_at,
            'invite_user_id' => $order->invite_user_id === null ? null : (int) $order->invite_user_id,
            'invite_user' => $order->invite_user ? [
                'id' => (int) $order->invite_user->id, 'email' => (string) $order->invite_user->email,
            ] : null,
            'commission_status' => $order->commission_status === null ? null : (int) $order->commission_status,
            'commission_balance' => (int) ($order->commission_balance ?? 0),
            'actual_commission_balance' => (int) ($order->actual_commission_balance ?? 0),
            'surplus_orders' => $surplus,
        ]);
    }

    public function paid(Request $request): JsonResponse
    {
        $order = Order::query()->where('trade_no', (string) $request->route('tradeNo'))->firstOrFail();
        if ((int) $order->status !== Order::STATUS_PENDING || (int) $order->total_amount < 0) {
            return TxapiResponse::error($request, 'ORDER_CONFLICT', 'Order cannot be marked paid', 409);
        }
        // Reuse the locked, idempotent billing state machine; not a direct
        // status column update. Its internal callback id is not provider proof.
        if (!(new OrderService($order))->paid('manual_operation')) {
            return TxapiResponse::error($request, 'ORDER_CONFLICT', 'Order cannot be marked paid', 409);
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $order = Order::query()->where('trade_no', (string) $request->route('tradeNo'))->firstOrFail();
        if (!(new OrderService($order))->cancel()) {
            return TxapiResponse::error($request, 'ORDER_CONFLICT', 'Only pending orders can be cancelled', 409);
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }
}
