<?php

namespace App\Extensions\Contracts;

final readonly class DomainEvent
{
    public function __construct(public string $eventId, public string $installationId, public string $kind,
        public string $businessRef, public array $data, public int $occurredAt, public string $correlationId) {}
}
