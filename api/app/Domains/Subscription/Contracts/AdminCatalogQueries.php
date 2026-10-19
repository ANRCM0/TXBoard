<?php

namespace App\Domains\Subscription\Contracts;

interface AdminCatalogQueries
{
    public function offers(int $page, int $perPage, ?int $planId = null, ?string $status = null): array;
    public function plans(int $page, int $perPage): array;
    public function publication(int $planId, ?int $revisionId = null): ?array;
    public function history(int $planId, int $page, int $perPage): ?array;
}
