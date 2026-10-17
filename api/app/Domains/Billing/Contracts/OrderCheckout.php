<?php

namespace App\Domains\Billing\Contracts;

interface OrderCheckout
{
    /** Owns the checkout workflow. External calls occur outside its SQL transactions. */
    public function start(int $userId, string $tradeNo, ?int $paymentMethod = null,
        ?string $paymentToken = null, ?string $requestKey = null, bool $wallet = false, ?string $walletMinor = null): array;
}
