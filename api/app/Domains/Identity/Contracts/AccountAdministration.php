<?php

namespace App\Domains\Identity\Contracts;

interface AccountAdministration
{
    public function create(int $actorId, string $email, string $password): ?CreatedAccount;
    public function status(int $actorId, int $userId, bool $expectedBanned, bool $banned): array;
    public function createBatch(int $actorId, array $accounts, string $key): array;
    public function profile(int $actorId, int $userId, string $expectedRevision, array $changes, string $key): array;
}
