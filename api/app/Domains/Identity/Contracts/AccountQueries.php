<?php

namespace App\Domains\Identity\Contracts;

/** Native self-service read projections; the entry point supplies its authenticated actor. */
interface AccountQueries
{
    public function account(AccountIdentity $identity): array;

    public function subscription(int $userId): array;
}
