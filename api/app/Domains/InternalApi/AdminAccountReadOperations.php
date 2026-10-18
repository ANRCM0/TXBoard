<?php

namespace App\Domains\InternalApi;

use App\Domains\Identity\Contracts\AdminAccountQueries;
use App\Domains\Identity\Contracts\AdminAccountListQuery;
use App\Models\User;

final class AdminAccountReadOperations
{
    public function __construct(private readonly AdminAccountQueries $queries) {}

    public function listing(User $actor, array $input): ReadOperationResult
    {
        $page = $this->queries->adminListing(new AdminAccountListQuery(
            $input['page'] ?? 1, $input['per_page'] ?? 20, $input['email'] ?? null, $input['banned'] ?? null));
        return new ReadOperationResult($page['items'], $page['meta']);
    }

    public function find(User $actor, array $input): ?ReadOperationResult
    {
        $data = $this->queries->adminFind((int) $input['user_id']);
        return $data === null ? null : new ReadOperationResult($data);
    }
}
