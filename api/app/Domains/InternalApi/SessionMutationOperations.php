<?php

namespace App\Domains\InternalApi;

use App\Domains\Identity\Contracts\AccountSessions;
use App\Models\User;

/** Only the executor's two compiled session commands may target these methods. */
final class SessionMutationOperations
{
    public function __construct(private readonly AccountSessions $sessions) {}

    public function revoke(User $actor, array $input): ?MutationOperationResult
    {
        if (!$this->sessions->revoke((int) $actor->id, (int) $input['session_id'])) return null;
        return new MutationOperationResult(['ok' => true]);
    }

    public function end(User $actor, array $input): MutationOperationResult
    {
        $current = $actor->currentAccessToken();
        $this->sessions->end((int) $actor->id,
            $current && method_exists($current, 'delete') ? (int) $current->getKey() : null);
        return new MutationOperationResult(['ok' => true]);
    }
}
