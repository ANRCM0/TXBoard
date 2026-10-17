<?php

namespace App\Domains\Billing\Contracts;

interface PaymentMethods
{
    /** Non-secret currently admitted CNY methods; availability is rechecked by checkout. */
    public function available(): array;
}
