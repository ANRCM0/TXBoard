<?php

namespace App\Domains\InternalApi;

use App\Domains\Billing\Contracts\OrderListQuery;
use App\Domains\Billing\Contracts\OrderQueries;
use App\Models\User;

final class OrderReadOperations
{
    public function __construct(private readonly OrderQueries $queries) {}

    public function listing(User $actor, array $input): ReadOperationResult
    {
        $page = $this->queries->listing((int) $actor->id, new OrderListQuery(
            (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 20),
            array_key_exists('status', $input) ? (int) $input['status'] : null,
        ));
        return new ReadOperationResult($page->items, $page->meta);
    }

    public function find(User $actor, array $input): ?ReadOperationResult
    {
        $order = $this->queries->find((int) $actor->id, $input['trade_no']);
        return $order === null ? null : new ReadOperationResult($order);
    }

    public function events(User $actor, array $input): ?ReadOperationResult
    {
        $page = $this->queries->events((int) $actor->id, $input['trade_no'], $input['after_sequence'] ?? '0',
            (int) ($input['limit'] ?? 50));
        return $page === null ? null : new ReadOperationResult($page->items, $page->meta);
    }
}
