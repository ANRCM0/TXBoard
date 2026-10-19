<?php

namespace App\Domains\Billing\Contracts;

interface Commissions
{
    public function policy(int $actorId, bool $admin): array;
    public function balance(int $actorId): array;
    public function listing(int $actorId, bool $admin, string $kind, int $page, int $perPage): OrderPage;
    public function execute(int $actorId, bool $admin, string $action, array $input, string $key): array;
}