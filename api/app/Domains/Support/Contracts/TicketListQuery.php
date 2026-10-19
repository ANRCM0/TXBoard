<?php

namespace App\Domains\Support\Contracts;

final readonly class TicketListQuery
{
    public function __construct(public int $page = 1, public int $perPage = 20,
        public ?int $status = null, public ?int $replyStatus = null, public ?int $userId = null,
        public string $assignment = 'any')
    {
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100
            || $status !== null && !in_array($status, [0, 1], true)
            || $replyStatus !== null && !in_array($replyStatus, [0, 1], true)
            || $userId !== null && $userId < 1
            || !in_array($assignment, ['any', 'mine', 'unassigned'], true)) throw new \InvalidArgumentException('TICKET_QUERY_INVALID');
    }
}
