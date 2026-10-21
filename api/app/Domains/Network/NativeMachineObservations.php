<?php

namespace App\Domains\Network;

use App\Models\ServerMachine;
use App\Services\NodeSyncService;

/** Public telemetry projection; deployment tokens and runtime messages never leave this owner. */
final class NativeMachineObservations
{
    public function project(ServerMachine $machine, int $now): array
    {
        $status = is_array($machine->load_status) ? $machine->load_status : [];
        $recorded = $status['updated_at'] ?? null;
        $fresh = is_int($recorded) && $recorded <= $now && $recorded >= $now - NodeSyncService::WS_TTL_SECONDS;
        $cpu = $fresh && is_numeric($status['cpu'] ?? null) ? (float) $status['cpu'] : null;
        if ($cpu !== null && (!is_finite($cpu) || $cpu < 0 || $cpu > 100)) $cpu = null;
        $bytes = static function (mixed $value): ?string {
            if (is_int($value) && $value >= 0) return (string) $value;
            return is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $value) ? $value : null;
        };
        $memoryTotal = $fresh ? $bytes($status['mem']['total'] ?? null) : null;
        $memoryUsed = $fresh ? $bytes($status['mem']['used'] ?? null) : null;
        $diskTotal = $fresh ? $bytes($status['disk']['total'] ?? null) : null;
        $diskUsed = $fresh ? $bytes($status['disk']['used'] ?? null) : null;
        $highRatio = static fn (?string $used, ?string $total): bool => $used !== null && $total !== null
            && bccomp($total, '0', 0) > 0 && bccomp(bcmul($used, '100', 0), bcmul($total, '80', 0), 0) >= 0;
        $hasLoad = $cpu !== null || ($memoryUsed !== null && $memoryTotal !== null && $memoryTotal !== '0')
            || ($diskUsed !== null && $diskTotal !== null && $diskTotal !== '0');
        return [
            'online' => NodeSyncService::isMachineOnline((int) $machine->id),
            'telemetry_fresh' => $fresh,
            'recorded_at' => is_int($recorded) && $recorded > 0 && $recorded <= $now ? $recorded : null,
            'cpu_percent' => $cpu === null ? null : rtrim(rtrim(sprintf('%.6F', $cpu), '0'), '.'),
            'memory_total_bytes' => $memoryTotal, 'memory_used_bytes' => $memoryUsed,
            'disk_total_bytes' => $diskTotal, 'disk_used_bytes' => $diskUsed,
            'high_load' => !$fresh || !$hasLoad ? null : (($cpu !== null && $cpu >= 80)
                || $highRatio($memoryUsed, $memoryTotal) || $highRatio($diskUsed, $diskTotal)),
        ];
    }

    public function overview(int $now): array
    {
        $counts = ['total' => 0, 'enabled' => 0, 'online' => 0, 'high_load' => 0, 'unknown_load' => 0];
        foreach (ServerMachine::query()->select(['id', 'is_active', 'load_status'])->lazyById(250) as $machine) {
            $row = $this->project($machine, $now);
            $counts['total']++;
            if ($machine->is_active) $counts['enabled']++;
            if ($row['online']) $counts['online']++;
            if ($row['high_load'] === true) $counts['high_load']++;
            if ($row['high_load'] === null) $counts['unknown_load']++;
        }
        return ['observed_at' => $now, 'freshness_seconds' => NodeSyncService::WS_TTL_SECONDS,
            'high_load_threshold_percent' => 80] + $counts;
    }
}
