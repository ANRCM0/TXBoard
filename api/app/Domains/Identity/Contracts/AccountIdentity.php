<?php

namespace App\Domains\Identity\Contracts;

use InvalidArgumentException;

/** Scalar projection of the current authenticated actor, not authorization proof. */
final readonly class AccountIdentity
{
    public function __construct(public int $userId, public string $email, public ?int $telegramId)
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('ACCOUNT_IDENTITY_INVALID');
        }
    }
}
