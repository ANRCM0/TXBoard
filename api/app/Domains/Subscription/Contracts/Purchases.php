<?php

namespace App\Domains\Subscription\Contracts;

interface Purchases
{
    /** Owns the purchase transaction. Replays bind the original actor, offer and permanent key. */
    public function purchase(int $userId, int $offerId, string $idempotencyKey): PurchaseResult;
}
