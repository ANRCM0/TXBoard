<?php

namespace App\Domains\Identity\Contracts;

/** Host-only reads. The management operation boundary owns current actor/grant checks. */
interface AdminAccountQueries
{
    public function adminListing(AdminAccountListQuery $query): array;

    public function adminFind(int $userId): ?array;
}
