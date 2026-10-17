<?php

namespace App\Domains\Subscription\Internal;

/** Internal SQL participant; preserves the installation -> account/slot/subscription lock order. */
interface LockedAccountAccess
{
    public function lock(array $installation, int $userId, int $now): array;
    public function stage(int $userId, iterable $subscriptions): void;
}
