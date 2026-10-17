<?php

namespace App\Domains\Network;

use App\Domains\Network\Contracts\NodeAuthorizationQueries;
use App\Domains\Network\Contracts\NodePrincipal;
use App\Models\Server;

final class NativeNodeAuthorizationQueries implements NodeAuthorizationQueries
{
    public function __construct(private readonly NativeProviderNodeAuthorization $authorization) {}
    public function publicTrust(): array { return $this->authorization->publicTrust(); }

    public function snapshot(NodePrincipal $principal): array
    {
        $node = Server::query()->useWritePdo()->findOrFail($principal->nodeId);
        return $this->authorization->snapshot($node, ['kind' => $principal->machineId === null ? 'node' : 'machine',
            'machine_id' => $principal->machineId, 'credential_sha256' => $principal->credentialSha256]);
    }
}
