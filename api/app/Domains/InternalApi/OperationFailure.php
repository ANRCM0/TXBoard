<?php

namespace App\Domains\InternalApi;

final class OperationFailure extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($errorCode);
    }
}
