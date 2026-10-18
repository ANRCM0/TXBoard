<?php

namespace App\Domains\InternalApi;

use App\Domains\Billing\Contracts\AdminOrderQueries;
use App\Domains\Billing\Contracts\AdminOrderListQuery;
use App\Models\User;

final class AdminOrderReadOperations
{
    public function __construct(private readonly AdminOrderQueries $queries) {}

    public function listing(User $actor, array $input): ReadOperationResult
    {
        $page = $this->queries->adminListing(new AdminOrderListQuery($input['page'] ?? 1, $input['per_page'] ?? 20,
            isset($input['user_id']) ? (int) $input['user_id'] : null, $input['trade_no'] ?? null, $input['status'] ?? null));
        return new ReadOperationResult($page->items, $page->meta);
    }

    public function find(User $actor, array $input): ?ReadOperationResult
    {
        $data = $this->queries->adminFind($input['trade_no']);
        return $data === null ? null : new ReadOperationResult($data);
    }

    public function events(User $actor, array $input): ?ReadOperationResult
    {
        $page = $this->queries->adminEvents($input['trade_no'], $input['after_sequence'] ?? '0',
            (int) ($input['limit'] ?? 50));
        return $page === null ? null : new ReadOperationResult($page->items, $page->meta);
    }
}
