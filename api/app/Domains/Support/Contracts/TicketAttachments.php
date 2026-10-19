<?php

namespace App\Domains\Support\Contracts;

interface TicketAttachments
{
    public function begin(int $actorId, array $input, string $key, bool $admin = false): array;
    public function append(int $actorId, array $input, string $key, bool $admin = false): array;
    public function finish(int $actorId, array $input, string $key, bool $admin = false): array;
    public function cancel(int $actorId, array $input, string $key, bool $admin = false): array;
    public function get(int $actorId, string $id, bool $admin = false): ?array;
    public function download(int $actorId, array $input, bool $admin = false): ?array;
    /** Caller holds installation admission, current session/grant, ticket and message locks. */
    public function bindLocked(array $installation, int $actorId, int $ticketId, int $messageId, array $ids, bool $admin): void;
    /** Caller has verified the current session and locked the owning ticket. */
    public function forMessagesLocked(array $installation, int $actorId, int $ticketId, array $messageIds, bool $admin): array;
}
