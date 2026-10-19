<?php

namespace App\Domains\Billing\Contracts;

interface CouponAdministration
{
    public function listing(int $actorId, int $page, int $perPage): OrderPage;
    public function find(int $actorId, string $id): ?array;
    public function execute(int $actorId, string $action, array $input, string $key): array;
}
