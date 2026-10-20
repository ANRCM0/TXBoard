<?php

namespace App\Domains\Subscription\Contracts;

/** Reads the owner's current lifecycle projection, including non-authorized states. */
interface SubscriptionLifecycleQueries
{
    public function current(int $userId): ?array;
}
