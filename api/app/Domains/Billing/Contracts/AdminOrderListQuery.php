<?php

namespace App\Domains\Billing\Contracts;

use InvalidArgumentException;

final readonly class AdminOrderListQuery
{
    public function __construct(public int $page = 1, public int $perPage = 20,
        public ?int $userId = null, public ?string $tradeNo = null, public ?int $status = null,
        public array $filters = [], public string $sortBy = 'created_at', public string $sortDirection = 'desc')
    {
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100
            || ($userId !== null && $userId < 1)
            || ($status !== null && ($status < 0 || $status > 4))
            || ($tradeNo !== null && !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $tradeNo))) {
            throw new InvalidArgumentException('ADMIN_ORDER_QUERY_INVALID');
        }
        if (!array_is_list($filters) || count($filters) > 10 || !preg_match('/\A[a-z_]{1,64}\z/D', $sortBy)
            || !in_array($sortDirection, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('ADMIN_ORDER_QUERY_INVALID');
        }
    }
}
