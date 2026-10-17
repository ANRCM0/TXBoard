<?php

namespace App\Domains\UsageV2\Contracts;

use RuntimeException;

final class UsageTargetMissing extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('NATIVE_USAGE_SUBSCRIPTION_NOT_FOUND');
    }
}
