<?php

namespace App\Observers;

use App\Models\Server;
use App\Services\NodeSyncService;

class ServerObserver
{
    // Capture save-time changes explicitly; the adapter owns the deferred callback.
    public bool $afterCommit = false;

    public function onCreated(Server $server): void
    {
        $machineId = $server->machine_id === null ? null : (int) $server->machine_id;
        $server->getConnection()->afterCommit(function () use ($machineId): void {
            $this->notifyMachineChange($machineId, null);
        });
    }

    public function onUpdated(Server $server): void
    {
        $nodeId = (int) $server->id;
        $newMachineId = $server->machine_id === null ? null : (int) $server->machine_id;
        $oldMachine = $server->getRawOriginal('machine_id');
        $oldMachineId = $oldMachine === null ? null : (int) $oldMachine;
        $groupChanged = $server->wasChanged('group_ids');
        $configChanged = $server->wasChanged(['server_port', 'protocol_settings', 'type', 'route_ids', 'custom_outbounds', 'custom_routes', 'cert_config']);
        $membershipChanged = $server->wasChanged(['machine_id', 'enabled']);
        if (!$groupChanged && !$configChanged && !$membershipChanged) return;
        $server->getConnection()->afterCommit(function () use ($nodeId, $newMachineId, $oldMachineId, $groupChanged, $configChanged, $membershipChanged): void {
            // Deliver current desired state, but decide which effects belong to
            // this event using its frozen flags and original owner.
            if ($groupChanged) {
                NodeSyncService::notifyFullSync($nodeId);
            } elseif ($configChanged) {
                NodeSyncService::notifyConfigUpdated($nodeId);
            }
            if ($membershipChanged) $this->notifyMachineChange($newMachineId, $oldMachineId);
        });
    }

    public function onDeleted(Server $server): void
    {
        $oldMachine = $server->getRawOriginal('machine_id') ?? $server->machine_id;
        $machineId = $oldMachine === null ? null : (int) $oldMachine;
        $server->getConnection()->afterCommit(function () use ($machineId): void {
            $this->notifyMachineChange(null, $machineId);
        });
    }

    // Explicit direct-call adapters retain existing observer consumers.
    public function created(Server $server): void { $this->onCreated($server); }
    public function updated(Server $server): void { $this->onUpdated($server); }
    public function deleted(Server $server): void { $this->onDeleted($server); }

    private function notifyMachineChange(?int $newMachineId, ?int $oldMachineId): void
    {
        if ($newMachineId) NodeSyncService::notifyMachineNodesChanged($newMachineId);
        if ($oldMachineId && $oldMachineId !== $newMachineId) NodeSyncService::notifyMachineNodesChanged($oldMachineId);
    }
}
