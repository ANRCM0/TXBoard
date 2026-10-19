<?php

namespace App\Domains\Identity\Internal;

/** SQL participant for built-in owners; actor IDs alone never authorize a call. */
interface CurrentSessionAuthorization
{
    public function assertCurrentLocked(int $userId, ?string $permission = null): void;
}
