<?php

namespace App\Domains\Subscription\Contracts;

use InvalidArgumentException;

final readonly class EntitlementQuery
{
    public function __construct(public int $userId)
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('ENTITLEMENT_INPUT_INVALID');
        }
    }
}
