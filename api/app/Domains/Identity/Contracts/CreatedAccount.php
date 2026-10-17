<?php

namespace App\Domains\Identity\Contracts;

final readonly class CreatedAccount
{
    public function __construct(public int $id, public string $email) {}
}
