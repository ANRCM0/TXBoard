<?php

namespace App\Domains\Billing\Internal;

use App\Models\Order;
use App\Models\OrderPurchaseTerms;

/** Internal SQL evidence participant, not a payment acceptance or journal-posting capability. */
interface WinningPaymentProofs
{
    /**
     * Requires admission/account/history/order/purchase locks in the owner transaction.
     * Locks the money union (initializing accounts if absent) and verifies payment evidence.
     * Free acceptance is separate; liability receipts cannot prove an order payment.
     */
    public function verifyWinningPaymentLocked(array $installation, int $userId,
        Order $order, OrderPurchaseTerms $terms): array;
}
