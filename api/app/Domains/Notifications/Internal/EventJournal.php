<?php

namespace App\Domains\Notifications\Internal;

use App\Extensions\Contracts\DomainEvent;

/** Internal SQL participant; the business owner has already verified and locked its committing facts. */
interface EventJournal
{
    public function appendLocked(DomainEvent $event): void;
}
