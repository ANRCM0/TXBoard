<?php

namespace App\Domains\InternalApi;

use App\Domains\Identity\Contracts\AccountSessions;
use App\Models\User;

final class SessionReadOperations
{
    public function __construct(private readonly AccountSessions $sessions) {}

    public function listing(User $actor, array $input): ReadOperationResult
    {
        $rows = $this->sessions->listing((int) $actor->id, 101);
        return new ReadOperationResult(array_slice($rows, 0, 100), ['has_more' => count($rows) > 100]);
    }
}
