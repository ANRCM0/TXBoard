<?php

namespace App\Domains\Billing;

use DomainException;

final class BillingError extends DomainException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
