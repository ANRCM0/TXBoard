<?php

namespace App\Extensions\Contracts;

interface NotificationChannel
{
    /** The same event ID is reused after an unknown result; the receiver must deduplicate it. */
    public function deliver(DomainEvent $event): DeliveryResult;
}
