<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Exceptions\ApiException;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

final class BillingController
{
    public function wallet(Request $request): JsonResponse
    {
        $user = Auth::guard('sanctum')->user()->fresh();
        return TxapiResponse::success($request, [
            'balance_minor' => (int) $user->balance,
            'commission_balance_minor' => (int) $user->commission_balance,
        ]);
    }

    public function commissions(Request $request): JsonResponse
    {
        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = CommissionLog::query()
            ->where('invite_user_id', Auth::guard('sanctum')->id())
            ->where('get_amount', '>', 0)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($params['per_page'] ?? 20),
                ['id', 'trade_no', 'order_amount', 'get_amount', 'created_at'],
                'page', (int) ($params['page'] ?? 1));
        return TxapiResponse::success($request,
            $page->getCollection()->map(static fn (CommissionLog $log): array => [
                'id' => (int) $log->id,
                'trade_no' => (string) $log->trade_no,
                'order_amount_minor' => (int) $log->order_amount,
                'earned_minor' => (int) $log->get_amount,
                'created_at' => Carbon::createFromTimestampUTC((int) $log->created_at)->toIso8601String(),
            ])->all(),
            ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()]);
    }

    public function methods(Request $request): JsonResponse
    {
        $methods = Payment::query()->where('enable', true)
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'name', 'payment', 'icon', 'handling_fee_fixed', 'handling_fee_percent'])
            ->map(static fn (Payment $method): array => [
                'id' => (int) $method->id,
                'name' => (string) $method->name,
                'provider' => (string) $method->payment,
                'icon' => $method->icon,
                'fee_fixed_minor' => (int) $method->handling_fee_fixed,
                'fee_percent' => (float) $method->handling_fee_percent,
            ])->all();
        return TxapiResponse::success($request, $methods);
    }

    public function checkCoupon(Request $request): JsonResponse
    {
        $params = $request->validate([
            'code' => ['required', 'string', 'max:128'],
            'plan_id' => ['required', 'integer', 'min:1'],
            'period' => ['required', 'string', 'max:40'],
        ]);
        try {
            $coupon = new CouponService($params['code']);
            $coupon->setPlanId((int) $params['plan_id']);
            $coupon->setUserId((int) Auth::guard('sanctum')->id());
            $coupon->setPeriod($params['period']);
            $coupon->check();
        } catch (ApiException) {
            return TxapiResponse::error($request, 'COUPON_INVALID', 'Coupon is unavailable', 422);
        }
        $found = $coupon->getCoupon();
        return TxapiResponse::success($request, [
            'id' => (int) $found->id,
            'name' => (string) $found->name,
            'code' => (string) $found->code,
            'type' => (int) $found->type,
            'value_minor' => (int) $found->type === 1 ? (int) $found->value : null,
            'percent' => (int) $found->type === 2 ? (float) $found->value : null,
        ]);
    }

    public function createOrder(Request $request): JsonResponse
    {
        $params = $request->validate([
            'plan_id' => ['required', 'integer', 'min:1'],
            'period' => ['required', 'string', 'max:40'],
            'coupon_code' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);
        $period = PlanService::getPeriodKey($params['period']);
        if (!in_array($period, PlanService::getNewPeriods(), true)) {
            return TxapiResponse::error($request, 'INVALID_PERIOD', 'Unsupported period', 422);
        }
        $plan = Plan::query()->find($params['plan_id']);
        if ($plan === null) {
            abort(404);
        }
        try {
            $order = OrderService::createFromRequest(Auth::guard('sanctum')->user(),
                $plan, $period, $params['coupon_code'] ?? null);
        } catch (ApiException) {
            return TxapiResponse::error($request, 'ORDER_REJECTED',
                'Order cannot be created under current subscription or billing state', 409);
        }
        return TxapiResponse::success($request, ['trade_no' => $order->trade_no], status: 201);
    }

    public function cancelOrder(Request $request, string $tradeNo): JsonResponse
    {
        $order = Order::query()->where('user_id', Auth::guard('sanctum')->id())
            ->where('trade_no', $tradeNo)->first();
        if (!$order) {
            abort(404);
        }
        if (!(new OrderService($order))->cancel()) {
            return TxapiResponse::error($request, 'ORDER_CONFLICT',
                'Only an unpaid order may be cancelled', 409);
        }
        return TxapiResponse::success($request, ['ok' => true]);
    }
}
