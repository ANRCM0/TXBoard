<?php

namespace App\Domains\UsageV2\Contracts;

final class UsageHistoryUnavailable extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
