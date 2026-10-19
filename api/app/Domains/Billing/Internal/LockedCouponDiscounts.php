<?php

namespace App\Domains\Billing\Internal;

/** Participant in the purchase owner's account -> plan -> campaign transaction. */
interface LockedCouponDiscounts
{
    public function quoteLocked(int $userId, int $offerId, string $currency, string $listMinor,
        string $code, int $now, string $installationId): array;
    public function persistLocked(int $orderId, array $quote): void;
}
