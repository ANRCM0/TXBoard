<?php

namespace App\Domains\InternalApi;

use App\Domains\Identity\Contracts\AccountIdentity;
use App\Domains\Identity\Contracts\AccountQueries;
use App\Models\User;

/** Fixed read handlers receive the executor's freshly authenticated actor, never asserted input. */
final class AccountReadOperations
{
    public function __construct(private readonly AccountQueries $queries) {}

    public function account(User $actor, array $input): ReadOperationResult
    {
        return new ReadOperationResult($this->queries->account(new AccountIdentity(
            (int) $actor->id, (string) $actor->email, $actor->telegram_id === null ? null : (int) $actor->telegram_id,
        )));
    }

    public function subscription(User $actor, array $input): ReadOperationResult
    {
        return new ReadOperationResult($this->queries->subscription((int) $actor->id));
    }
}
