<?php

namespace App\Domains\Billing;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Services\OrderService;
use App\Domains\Subscription\Services\PublishedOrderService;
use Illuminate\Support\Facades\DB;

/**
 * TXAPI checkout transition. Paid execution uses native frozen dispatch only;
 * legacy free-order behavior has no payment plugin entry point.
 */
final class OrderCheckout implements \App\Domains\Billing\Contracts\OrderCheckout
{
    /** @return array{type:int,data:mixed} */
    public function start(int $userId, string $tradeNo, ?int $paymentMethod = null,
        ?string $paymentToken = null, ?string $requestKey = null, bool $wallet = false, ?string $walletMinor = null): array
    {
        $locator = Order::query()->useWritePdo()->where('user_id', $userId)->where('trade_no', $tradeNo)->first();
        if ($locator === null) throw new ApiException('Order does not exist', 404);
        $native = $locator?->purchase_terms_version === PublishedOrderService::VERSION;
        if ($native && (string) $locator->total_amount === '0') {
            if ($requestKey === null) { throw new BillingError('PAYMENT_IDEMPOTENCY_KEY_REQUIRED'); }
            return app(NativeFreeCheckout::class)->start($userId, (int) $locator->id, $requestKey, [
                'method' => $paymentMethod === null ? null : (string) $paymentMethod,
                'token_hash' => $paymentToken === null ? null : hash('sha256', $paymentToken),
                'wallet' => $wallet, 'wallet_minor' => $walletMinor,
            ]);
        }
        if ($native && (string) $locator->total_amount !== '0') {
            if ($paymentToken !== null || ($wallet && ($paymentMethod !== null || $walletMinor !== null))) {
                throw new ApiException('Native checkout input is invalid', 422);
            }
            if ($requestKey === null) {
                throw new BillingError('PAYMENT_IDEMPOTENCY_KEY_REQUIRED');
            }
            if ($wallet) {
                return app(\App\Domains\Billing\Contracts\WalletPayments::class)->pay($userId, (int) $locator->id, $requestKey);
            }
            if ($paymentMethod === null || $paymentMethod < 1) {
                throw new ApiException('Payment method is required', 422);
            }
            $started = app(\App\Domains\Billing\Contracts\PaymentCheckout::class)->start($userId, (int) $locator->id, $paymentMethod, $requestKey, $walletMinor);
            return $started['payment'] + ['attempt' => $started['attempt']];
        }
        if (!$native && (int) $locator->total_amount > 0) {
            throw new ApiException('Payment execution requires a native frozen dispatch', 409);
        }
        $order = DB::transaction(static function () use ($userId, $tradeNo): Order {
            app(\App\Domains\Installation\InstallationAuthority::class)->assertLegacyWriter();
            $order = Order::query()->where('user_id', $userId)->where('trade_no', $tradeNo)->lockForUpdate()->first();
            if ($order === null) { throw new ApiException('Order does not exist', 404); }
            if ($order->purchase_terms_version === PublishedOrderService::VERSION) {
                throw new ApiException('Native order requires native checkout admission', 409);
            }
            if ((int) $order->status !== Order::STATUS_PENDING) {
                throw new ApiException('Only pending orders can be checked out', 409);
            }
            if ((int) $order->total_amount < 0) { throw new ApiException('Order amount is invalid', 409); }
            if ((int) $order->total_amount !== 0) {
                throw new ApiException('Payment execution requires a native frozen dispatch', 409);
            }
            return $order;
        });
        if (!(new OrderService($order))->paid($order->trade_no)) {
            throw new ApiException('Payment transition conflict', 409);
        }
        return ['type' => -1, 'data' => true];
    }
}
