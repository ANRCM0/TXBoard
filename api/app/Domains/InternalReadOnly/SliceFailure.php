<?php

namespace App\Domains\InternalReadOnly;

final class SliceFailure extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status)
    {
        // Never carry caller data, credentials or underlying database errors.
        parent::__construct($errorCode);
    }
}
