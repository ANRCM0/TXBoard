<?php

namespace App\Domains\Billing\Contracts;

interface OrderCancellation
{
    /** Null when not owned/found. The owner locks and rechecks the unpaid transition. */
    public function cancel(int $userId, string $tradeNo): ?bool;
}
