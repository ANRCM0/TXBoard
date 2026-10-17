<?php

namespace App\Extensions\Contracts;

use InvalidArgumentException;

final readonly class DeliveryResult
{
    public function __construct(public string $status, public string $code)
    {
        if (!in_array($status, ['accepted', 'refused', 'unknown'], true)
            || preg_match('/\A[A-Z][A-Z0-9_]{0,63}\z/D', $code) !== 1) {
            throw new InvalidArgumentException('MODULE_DELIVERY_RESULT_INVALID');
        }
    }
}
