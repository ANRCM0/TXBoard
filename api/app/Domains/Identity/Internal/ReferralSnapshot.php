<?php

namespace App\Domains\Identity\Internal;

interface ReferralSnapshot
{
    public function forAccountLocked(string $installationId, int $userId): ?array;
}
