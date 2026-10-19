<?php

namespace App\Domains\InternalApi;

use App\Domains\Support\Contracts\TicketCommands;
use App\Models\User;

final class SupportMutationOperations
{
    public function __construct(private readonly TicketCommands $commands) {}

    public function create(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->commands->create((int) $actor->id, $input, $idempotencyKey), 201);
    }

    public function reply(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->commands->reply((int) $actor->id, $input, $idempotencyKey));
    }

    public function adminReply(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->commands->reply((int) $actor->id, $input, $idempotencyKey, true));
    }

    public function close(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->commands->close((int) $actor->id, $input, $idempotencyKey));
    }

    public function adminClose(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->commands->close((int) $actor->id, $input, $idempotencyKey, true));
    }

    public function adminClaim(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->commands->claim((int) $actor->id, $input, $idempotencyKey));
    }

    public function adminUnassign(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->commands->unassign((int) $actor->id, $input, $idempotencyKey));
    }
}
