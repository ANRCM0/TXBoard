<?php

namespace App\Domains\UsageV2;

final class UsageError extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus = 422)
    {
        parent::__construct($errorCode);
    }
}
