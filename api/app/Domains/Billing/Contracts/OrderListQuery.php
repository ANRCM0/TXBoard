<?php

namespace App\Domains\Billing\Contracts;

use InvalidArgumentException;

final readonly class OrderListQuery
{
    public function __construct(public int $page = 1, public int $perPage = 20, public ?int $status = null)
    {
        if ($page < 1 || $perPage < 1 || $perPage > 100 || ($status !== null && ($status < 0 || $status > 4))) {
            throw new InvalidArgumentException('ORDER_LIST_INPUT_INVALID');
        }
    }
}
