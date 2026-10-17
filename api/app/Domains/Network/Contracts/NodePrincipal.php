<?php

namespace App\Domains\Network\Contracts;

/** Checked ingress identity; the owner verifies current credential and Node/Machine binding again. */
final readonly class NodePrincipal
{
    public function __construct(public int $nodeId, public ?int $machineId, public string $credentialSha256)
    {
        if ($nodeId < 1 || $machineId !== null && $machineId < 1 || preg_match('/\A[0-9a-f]{64}\z/D', $credentialSha256) !== 1) {
            throw new \DomainException('NODE_PRINCIPAL_INVALID');
        }
    }
}
