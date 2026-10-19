<?php

namespace App\Domains\Support;

final class SupportFailure extends \DomainException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 422)
    {
        parent::__construct($errorCode);
    }
}
