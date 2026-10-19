<?php

namespace App\Domains\Subscription\Contracts;

/** Current-session browser discovery and recipient-bound, short-lived render authorization. */
interface BrowserDeliveries
{
    public function browserOptions(int $actorId): array;
    public function browserAuthorize(int $actorId, array $input): array;
}
