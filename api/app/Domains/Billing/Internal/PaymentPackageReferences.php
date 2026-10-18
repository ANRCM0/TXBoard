<?php

namespace App\Domains\Billing\Internal;

interface PaymentPackageReferences
{
    /** Caller holds the installed package fence in the current SQL transaction. Historical dispatches pin the package. */
    public function retained(string $code): bool;
}
