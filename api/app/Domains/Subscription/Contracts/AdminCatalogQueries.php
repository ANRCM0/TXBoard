<?php

namespace App\Domains\Subscription\Contracts;

interface AdminCatalogQueries
{
    public function offers(int $page, int $perPage, ?int $planId = null): array;
}
