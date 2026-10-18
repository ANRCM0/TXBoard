<?php

namespace App\Core\Kernel\Execution;

use InvalidArgumentException;

/** Attribution only. A reference is never proof of current permissions or ownership. */
final readonly class ActorRef
{
    public function __construct(public string $kind, public string $id)
    {
        if (!in_array($kind, ['user', 'admin', 'service', 'node', 'system', 'callback'], true)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $id) !== 1) {
            throw new InvalidArgumentException('INVOCATION_ACTOR_INVALID');
        }
    }
}
