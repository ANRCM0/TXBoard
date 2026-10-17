<?php

namespace App\Domains\Subscription\Contracts;

/** Owns a fresh SQL transaction and then drives postcommit credential synchronization. */
interface OrderFulfillment
{
    /** The optional boundary time is trusted server input, never a client override. */
    public function fulfill(int $orderId, ?int $now = null): array;
}
