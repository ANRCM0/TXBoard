<?php

namespace App\Services;

use App\Models\ServerMachine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MachineRuntimeUpdateService
{
    private const HEARTBEAT_MAX_AGE_SECONDS = 180;
    private const REQUEST_COOLDOWN_SECONDS = 45;
    public const EVENT = 'ops.machine.runtime.update';

    /**
     * Request a bounded Installer-owned TX-Node runtime update.
     *
     * TXBoard owns only orchestration. Deployment/download/rollback behavior
     * remains authoritative in TX-Node-Installer.
     *
     * @return array{machine_id:int,request_id:string,target:string,status:string}
     */
    public function request(ServerMachine $machine, string $target): array
    {
        if (!in_array($target, ['latest', 'dev'], true)) {
            throw new \InvalidArgumentException('Only stable (latest) and development (dev) update targets are supported');
        }
        if (!$machine->is_active) {
            throw new \InvalidArgumentException('Machine is disabled');
        }

        $lastSeenAt = (int) ($machine->last_seen_at ?? 0);
        if ($lastSeenAt <= 0 || $lastSeenAt < now()->timestamp - self::HEARTBEAT_MAX_AGE_SECONDS) {
            throw new \InvalidArgumentException('Machine heartbeat is stale or offline');
        }
        if (!NodeSyncService::isMachineOnline((int) $machine->id)) {
            throw new \InvalidArgumentException('Machine WebSocket is offline');
        }

        $runtime = is_array($machine->load_status)
            ? ($machine->load_status['runtime'] ?? null)
            : null;
        if (!is_array($runtime) || ($runtime['updater_available'] ?? false) !== true) {
            throw new \InvalidArgumentException('Machine runtime updater is unavailable; update TX-Node Installer first');
        }

        $requestId = 'mup_' . Str::lower((string) Str::ulid());
        $cooldownKey = "machine_runtime_update:cooldown:{$machine->id}";
        if (!Cache::add($cooldownKey, $requestId, self::REQUEST_COOLDOWN_SECONDS)) {
            throw new \InvalidArgumentException('A machine runtime update was requested recently; wait before retrying');
        }

        $published = NodeSyncService::pushMachine((int) $machine->id, self::EVENT, [
            'request_id' => $requestId,
            'target' => $target,
        ]);

        if (!$published) {
            Cache::forget($cooldownKey);
            throw new \RuntimeException('Failed to dispatch machine runtime update');
        }

        return [
            'machine_id' => (int) $machine->id,
            'request_id' => $requestId,
            'target' => 'latest',
            'status' => 'accepted',
        ];
    }
}
