<?php

namespace App\Domains\Subscription;

use App\Domains\Subscription\Contracts\Purchases;
use App\Domains\Subscription\Contracts\CouponPurchases;
use App\Domains\Subscription\Contracts\PurchaseResult;
use App\Domains\Subscription\Services\PublishedOrderService;

final class NativePurchases implements Purchases, CouponPurchases
{
    public function __construct(private readonly PublishedOrderService $orders) {}

    public function purchase(int $userId, int $offerId, string $idempotencyKey): PurchaseResult
    {
        $order = $this->orders->create($userId, $offerId, $idempotencyKey);
        return new PurchaseResult((string) $order->id, (string) $order->trade_no, (int) $order->status,
            (string) $order->total_amount, (string) $order->purchaseTerms->currency, (string) $order->purchase_terms_version);
    }

    public function purchaseWithCoupon(int $userId, int $offerId, string $code, string $idempotencyKey): PurchaseResult
    {
        $order = $this->orders->create($userId, $offerId, $idempotencyKey, ['coupon_code' => $code]);
        return new PurchaseResult((string) $order->id, (string) $order->trade_no, (int) $order->status,
            (string) $order->total_amount, (string) $order->purchaseTerms->currency, (string) $order->purchase_terms_version);
    }
}
