<?php

namespace App\Domains\InternalApi;

use App\Domains\Identity\Contracts\AccountSessions;
use App\Models\User;

final class SessionReadOperations
{
    public function __construct(private readonly AccountSessions $sessions) {}

    public function listing(User $actor, array $input): ReadOperationResult
    {
        return new ReadOperationResult($this->sessions->listing((int) $actor->id));
    }
}
