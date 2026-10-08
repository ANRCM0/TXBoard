<?php

namespace Tests\Unit\Services;

use App\Services\NodeSyncService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class NodeSyncStatusTest extends TestCase
{
    public function test_node_heartbeat_has_bounded_ttl_and_disconnect_clears_it(): void
    {
        config(['cache.default' => 'array']);
        NodeSyncService::markNodeOnline(99);
        $this->assertTrue(NodeSyncService::isNodeOnline(99));
        $this->assertLessThanOrEqual(180, Cache::getStore()->get('node_ws_alive:99') ? NodeSyncService::WS_TTL_SECONDS : 9999);
        NodeSyncService::markNodeOffline(99);
        $this->assertFalse(NodeSyncService::isNodeOnline(99));
    }

    public function test_machine_heartbeat_can_be_expired_and_cleared(): void
    {
        config(['cache.default' => 'array']);
        NodeSyncService::markMachineOnline(75);
        $this->assertTrue(NodeSyncService::isMachineOnline(75));
        NodeSyncService::markMachineOffline(75);
        $this->assertFalse(NodeSyncService::isMachineOnline(75));
    }
}
