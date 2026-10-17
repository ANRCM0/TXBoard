<?php

namespace App\Domains\Identity\Contracts;

interface AccountAdministration
{
    public function create(int $actorId, string $email, string $password): ?CreatedAccount;
    public function status(int $actorId, int $userId, bool $expectedBanned, bool $banned): array;
}
