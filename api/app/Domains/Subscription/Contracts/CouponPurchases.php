<?php

namespace App\Domains\Subscription\Contracts;

interface CouponPurchases
{
    public function purchaseWithCoupon(int $userId, int $offerId, string $couponCode, string $idempotencyKey): PurchaseResult;
}
