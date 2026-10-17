<?php

namespace App\Domains\Subscription\Internal;

use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use Illuminate\Support\Collection;

/** SQL-only participant, restricted to the existing account-first aggregate workflow. */
interface LockedOrderFulfillment
{
    /**
     * Caller owns admission, account, complete history, order/purchase and financial/source locks.
     * Inputs do not prove locks or payment. Implementation must revalidate authoritative evidence.
     * Stages durable synchronization intent only; no commit or external I/O here.
     */
    public function applyLocked(array $installation, object $user, object $slot, Collection $subscriptions,
        array $histories, Order $order, ?OrderPurchaseTerms $purchase, int $now): array;
}
