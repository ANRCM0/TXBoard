<?php

namespace App\Domains\Billing\Internal;

use App\Models\Order;
use App\Models\OrderPurchaseTerms;

interface CommissionTerms
{
    /** Caller holds admission, buyer, order and frozen purchase locks. */
    public function captureLocked(array $installation, Order $order, OrderPurchaseTerms $purchase): void;
}
