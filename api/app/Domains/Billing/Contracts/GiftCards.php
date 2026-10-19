<?php

namespace App\Domains\Billing\Contracts;

interface GiftCards
{
    public function listing(int $actorId, bool $admin, int $page, int $perPage): OrderPage;
    public function execute(int $actorId, string $action, array $input, string $key, bool $admin = false): array;
    public function issueBatch(int $actorId, array $input, string $key): array;
    public function batches(int $actorId, bool $admin, int $page, int $perPage): OrderPage;
    public function batch(int $actorId, string $batchId, bool $admin = false): ?array;
}
