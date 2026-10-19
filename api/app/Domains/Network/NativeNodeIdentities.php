<?php

namespace App\Domains\Network;

use App\Domains\Network\Contracts\NodeIdentities;
use App\Models\Server;

final class NativeNodeIdentities implements NodeIdentities
{
    public function findMany(array $nodeIds): array
    {
        return Server::query()->whereIn('id', $nodeIds)->get(['id', 'name'])
            ->map(static fn (Server $node): array => ['id' => (string) $node->id, 'name' => $node->name])->all();
    }
}
