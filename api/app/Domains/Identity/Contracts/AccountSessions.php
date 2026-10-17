<?php

namespace App\Domains\Identity\Contracts;

/** Entry points supply the actual authenticated actor and current deletable token. */
interface AccountSessions
{
    public function listing(int $userId, ?int $limit = null): array;

    public function revoke(int $userId, int $sessionId): bool;

    public function end(int $userId, ?int $currentTokenId): void;
}
