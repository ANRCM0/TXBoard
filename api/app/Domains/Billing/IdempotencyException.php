<?php

namespace App\Domains\Billing;

use App\Exceptions\ApiException;

final class IdempotencyException extends ApiException
{
    public function __construct(string $message, int $status, public readonly string $errorCode)
    {
        parent::__construct($message, $status);
    }
}
