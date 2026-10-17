<?php

namespace App\Extensions\Contracts;

use App\Core\Kernel\Execution\InvocationContext;

/** The host creates this from a freshly authenticated actor; domains still recheck current authority. */
final readonly class ExtensionRequest
{
    public function __construct(public string $module, public string $operation, public int $userId,
        public string $actorKind, public array $input, public ?string $idempotencyKey,
        public InvocationContext $invocation) {}
}
