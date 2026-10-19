<?php

namespace App\Domains\Support\Contracts;

interface TicketQueries
{
    public function listing(int $actorId, TicketListQuery $query, bool $admin = false): TicketPage;
    public function find(int $actorId, int $ticketId, bool $admin = false): ?array;
    public function messages(int $actorId, int $ticketId, string $afterId, int $limit, bool $admin = false): ?TicketPage;
    public function audit(int $actorId, int $ticketId, string $afterId, int $limit): ?TicketPage;
}
