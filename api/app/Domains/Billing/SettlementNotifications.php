<?php

namespace App\Domains\Billing;

use App\Core\Kernel\Execution\CurrentInvocation;
use App\Domains\Notifications\Internal\EventJournal;
use App\Extensions\Contracts\DomainEvent;
use Illuminate\Support\Str;

/** Called only after the verified receipt and balanced journal exist in the owner's transaction. */
final class SettlementNotifications
{
    public function __construct(private readonly EventJournal $events, private readonly CurrentInvocation $context) {}

    public function recordLocked(array $installation, string $kind, string $reference, array $data, int $occurredAt): void
    {
        $id = hash('sha256', $installation['installation_id'].':'.$kind.':'.$reference);
        $this->events->appendLocked(new DomainEvent($id, $installation['installation_id'], $kind, $reference,
            $data, $occurredAt, $this->context->current()?->correlationId ?? (string) Str::uuid()));
    }
}
