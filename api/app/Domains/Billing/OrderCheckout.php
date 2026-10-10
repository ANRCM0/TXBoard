<?php

namespace App\Domains\Billing;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;

/**
 * Shared V1/TXAPI checkout transition. No provider network interaction is
 * performed while a database row lock is held. Amounts are integer cents.
 */
final class OrderCheckout
{
    /** @return array{type:int,data:mixed} */
    public function start(int $userId, string $tradeNo, ?int $paymentMethod = null,
        ?string $paymentToken = null): array
    {
        $prepared = DB::transaction(static function () use (
            $userId, $tradeNo, $paymentMethod
        ): array {
            $order = Order::query()->where('user_id', $userId)
                ->where('trade_no', $tradeNo)->lockForUpdate()->first();
            if (!$order) {
                throw new ApiException('Order does not exist', 404);
            }
            if ((int) $order->status !== Order::STATUS_PENDING) {
                throw new ApiException('Only pending orders can be checked out', 409);
            }
            if ((int) $order->total_amount < 0) {
                throw new ApiException('Order amount is invalid', 409);
            }
            if ((int) $order->total_amount === 0) {
                return ['kind' => 'free', 'order' => $order];
            }
            if ($paymentMethod === null || $paymentMethod < 1) {
                throw new ApiException('Payment method is required', 422);
            }
            $payment = Payment::query()->whereKey($paymentMethod)
                ->where('enable', true)->first();
            if (!$payment) {
                throw new ApiException('Payment method is not available', 422);
            }
            $provider = new PaymentService($payment->payment, $payment->id);
            $fee = ($payment->handling_fee_fixed || $payment->handling_fee_percent)
                ? (int) round(
                    $order->total_amount * ((float) $payment->handling_fee_percent / 100)
                    + (float) $payment->handling_fee_fixed
                )
                : null;
            if ($fee !== null && $fee < 0) {
                throw new ApiException('Invalid payment fee', 422);
            }
            $order->handling_amount = $fee;
            $order->payment_id = $paymentMethod;
            $order->saveOrFail();
            return ['kind' => 'paid', 'order' => $order, 'provider' => $provider];
        });

        /** @var Order $order */
        $order = $prepared['order'];
        if ($prepared['kind'] === 'free') {
            if (!(new OrderService($order))->paid($order->trade_no)) {
                throw new ApiException('Payment transition conflict', 409);
            }
            return ['type' => -1, 'data' => true];
        }

        /** @var PaymentService $provider */
        $provider = $prepared['provider'];
        $result = $provider->pay([
            'trade_no' => $order->trade_no,
            'total_amount' => (int) $order->total_amount
                + (int) ($order->handling_amount ?? 0),
            'user_id' => $userId,
            'stripe_token' => $paymentToken,
        ]);
        if (!is_array($result) || !isset($result['type'], $result['data'])) {
            throw new ApiException('Invalid payment provider response', 502);
        }
        return ['type' => (int) $result['type'], 'data' => $result['data']];
    }
}
