<?php

namespace App\Domains\Identity\Contracts;

interface Referrals
{
    public function read(int $userId): array;
    public function execute(int $userId, string $action, array $input, string $key): array;
}
