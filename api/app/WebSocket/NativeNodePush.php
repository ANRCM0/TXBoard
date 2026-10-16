<?php

namespace App\WebSocket;

use App\Models\Server;
use App\Services\NodeRegistry;

/** Socket events only request a fresh signed HTTP authorization snapshot. */
final class NativeNodePush
{
    public static function pushFullSync(Server $node): void
    {
        NodeRegistry::send((int) $node->id, 'sync.users', ['refresh_required' => true]);
    }
}
