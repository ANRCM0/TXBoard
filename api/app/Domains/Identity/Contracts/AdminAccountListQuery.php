<?php

namespace App\Domains\Identity\Contracts;

use InvalidArgumentException;

final readonly class AdminAccountListQuery
{
    public function __construct(public int $page = 1, public int $perPage = 20,
        public ?string $email = null, public ?bool $banned = null, public array $filters = [],
        public string $sortBy = 'id', public string $sortDirection = 'desc')
    {
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100
            || ($email !== null && (strlen($email) > 254 || $email === ''))) {
            throw new InvalidArgumentException('ADMIN_ACCOUNT_QUERY_INVALID');
        }
        if (!array_is_list($filters) || count($filters) > 10 || !preg_match('/\A[a-z_]{1,64}\z/D', $sortBy)
            || !in_array($sortDirection, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('ADMIN_ACCOUNT_QUERY_INVALID');
        }
    }
}
