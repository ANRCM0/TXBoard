<?php

namespace App\Domains\InternalApi;

use App\Domains\Billing\Contracts\AdminOrderCommands;
use App\Models\User;

final class AdminOrderMutationOperations
{
    public function __construct(private readonly AdminOrderCommands $commands) {}

    public function close(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->commands->execute((int) $actor->id, 'close', $input, $idempotencyKey));
    }

    public function retry(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->commands->execute((int) $actor->id, 'retry', $input, $idempotencyKey));
    }
}
