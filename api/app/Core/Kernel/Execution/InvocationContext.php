<?php

namespace App\Core\Kernel\Execution;

use InvalidArgumentException;

final readonly class InvocationContext
{
    public function __construct(public string $invocationId, public string $correlationId,
        public string $source, public ?ActorRef $actor = null, public ?ActorRef $caller = null,
        public ?string $installationId = null, public ?string $authorityEpoch = null,
        public ?int $deadline = null)
    {
        foreach ([$invocationId, $correlationId] as $id) {
            if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/Di', $id) !== 1) {
                throw new InvalidArgumentException('INVOCATION_ID_INVALID');
            }
        }
        if (!in_array($source, ['web', 'internal', 'cli', 'worker', 'scheduler', 'callback'], true)
            || ($installationId === null) !== ($authorityEpoch === null)
            || $installationId !== null && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/Di', $installationId) !== 1
            || $authorityEpoch !== null && preg_match('/\A[1-9][0-9]{0,18}\z/D', $authorityEpoch) !== 1
            || $deadline !== null && $deadline < 1) {
            throw new InvalidArgumentException('INVOCATION_CONTEXT_INVALID');
        }
    }
}
