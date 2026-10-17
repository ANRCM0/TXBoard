<?php

namespace App\Domains\UsageV2\Contracts;

use InvalidArgumentException;

final readonly class UsageQuery
{
    public function __construct(public ?int $subscriptionId = null, public int $afterId = 0, public int $limit = 100)
    {
        if (($subscriptionId !== null && $subscriptionId < 1) || $afterId < 0 || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('NATIVE_USAGE_INPUT_INVALID');
        }
    }
}
