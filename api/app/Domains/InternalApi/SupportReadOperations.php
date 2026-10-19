<?php

namespace App\Domains\InternalApi;

use App\Domains\Support\Contracts\TicketListQuery;
use App\Domains\Support\Contracts\TicketQueries;
use App\Models\User;

final class SupportReadOperations
{
    public function __construct(private readonly TicketQueries $queries) {}

    public function listing(User $actor, array $input): ReadOperationResult { return $this->list($actor, $input, false); }
    public function adminListing(User $actor, array $input): ReadOperationResult { return $this->list($actor, $input, true); }
    public function find(User $actor, array $input): ?ReadOperationResult { return $this->detail($actor, $input, false); }
    public function adminFind(User $actor, array $input): ?ReadOperationResult { return $this->detail($actor, $input, true); }
    public function messages(User $actor, array $input): ?ReadOperationResult { return $this->messagePage($actor, $input, false); }
    public function adminMessages(User $actor, array $input): ?ReadOperationResult { return $this->messagePage($actor, $input, true); }

    public function adminAudit(User $actor, array $input): ?ReadOperationResult
    {
        $page = $this->queries->audit((int) $actor->id, (int) $input['ticket_id'],
            $input['after_id'] ?? '0', (int) ($input['limit'] ?? 20));
        return $page === null ? null : new ReadOperationResult($page->items, $page->meta);
    }

    private function list(User $actor, array $input, bool $admin): ReadOperationResult
    {
        $page = $this->queries->listing((int) $actor->id, new TicketListQuery(
            (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 20),
            isset($input['status']) ? (int) $input['status'] : null,
            isset($input['reply_status']) ? (int) $input['reply_status'] : null,
            $admin && isset($input['user_id']) ? (int) $input['user_id'] : null,
            $admin ? ($input['assignment'] ?? 'any') : 'any'), $admin);
        return new ReadOperationResult($page->items, $page->meta);
    }

    private function detail(User $actor, array $input, bool $admin): ?ReadOperationResult
    {
        $ticket = $this->queries->find((int) $actor->id, (int) $input['ticket_id'], $admin);
        return $ticket === null ? null : new ReadOperationResult($ticket,
            $admin ? ['current_admin_id' => (string) $actor->id] : []);
    }

    private function messagePage(User $actor, array $input, bool $admin): ?ReadOperationResult
    {
        $page = $this->queries->messages((int) $actor->id, (int) $input['ticket_id'],
            $input['after_id'] ?? '0', (int) ($input['limit'] ?? 20), $admin);
        return $page === null ? null : new ReadOperationResult($page->items, $page->meta);
    }
}
