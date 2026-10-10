<?php

namespace App\Domains\Network;

use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;

/**
 * Same validated machine telemetry persistence for legacy V2 and native v1.
 * Installer update messages are informational and must never expose secrets.
 */
final class MachineTelemetry
{
    public static function rules(): array
    {
        return [
            'cpu' => 'required|numeric|min:0|max:100',
            'mem.total' => 'required|integer|min:0',
            'mem.used' => 'required|integer|min:0',
            'swap.total' => 'nullable|integer|min:0',
            'swap.used' => 'nullable|integer|min:0',
            'disk.total' => 'nullable|integer|min:0',
            'disk.used' => 'nullable|integer|min:0',
            'net.in_speed' => 'nullable|numeric|min:0',
            'net.out_speed' => 'nullable|numeric|min:0',
            'runtime' => 'nullable|array',
            'runtime.version' => 'nullable|string|max:64',
            'runtime.build_time' => 'nullable|string|max:64',
            'runtime.deployment' => 'nullable|in:docker,unknown',
            'runtime.updater_available' => 'nullable|boolean',
            'runtime.update' => 'nullable|array',
            'runtime.update.request_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'runtime.update.target' => 'nullable|in:latest,dev',
            'runtime.update.status' => 'nullable|in:accepted,running,succeeded,failed,rolled_back',
            'runtime.update.updated_at' => 'nullable|integer|min:1',
            'runtime.update.message' => 'nullable|string|max:160',
        ];
    }

    public function record(ServerMachine $machine, array $params): void
    {
        $recordedAt = now()->timestamp;
        $status = [
            'cpu' => (float) $params['cpu'],
            'mem' => [
                'total' => (int) $params['mem']['total'],
                'used' => (int) $params['mem']['used'],
            ],
            'swap' => [
                'total' => (int) ($params['swap']['total'] ?? 0),
                'used' => (int) ($params['swap']['used'] ?? 0),
            ],
            'disk' => [
                'total' => (int) ($params['disk']['total'] ?? 0),
                'used' => (int) ($params['disk']['used'] ?? 0),
            ],
            'updated_at' => $recordedAt,
        ];
        $inSpeed = $params['net']['in_speed'] ?? null;
        $outSpeed = $params['net']['out_speed'] ?? null;
        if ($inSpeed !== null && $outSpeed !== null) {
            $status['net'] = [
                'in_speed' => (float) $inSpeed,
                'out_speed' => (float) $outSpeed,
            ];
        }
        if (isset($params['runtime']) && is_array($params['runtime'])) {
            $status['runtime'] = $this->normalizeRuntime($params['runtime']);
        }
        $machine->forceFill([
            'load_status' => $status,
            'last_seen_at' => $recordedAt,
        ])->saveOrFail();
        $history = [
            'machine_id' => $machine->id,
            'cpu' => (float) $params['cpu'],
            'mem_total' => (int) $params['mem']['total'],
            'mem_used' => (int) $params['mem']['used'],
            'disk_total' => (int) ($params['disk']['total'] ?? 0),
            'disk_used' => (int) ($params['disk']['used'] ?? 0),
            'recorded_at' => $recordedAt,
        ];
        if ($inSpeed !== null && $outSpeed !== null) {
            $history['net_in_speed'] = (float) $inSpeed;
            $history['net_out_speed'] = (float) $outSpeed;
        }
        ServerMachineLoadHistory::create($history);
        if (random_int(1, 20) === 1) {
            ServerMachineLoadHistory::query()
                ->where('machine_id', $machine->id)
                ->where('recorded_at', '<', now()->subDay()->timestamp)
                ->delete();
        }
    }

    private function normalizeRuntime(array $runtime): array
    {
        $normalized = [
            'version' => (string) ($runtime['version'] ?? ''),
            'build_time' => (string) ($runtime['build_time'] ?? ''),
            'deployment' => (string) ($runtime['deployment'] ?? 'unknown'),
            'updater_available' => (bool) ($runtime['updater_available'] ?? false),
        ];
        if (isset($runtime['update']) && is_array($runtime['update'])) {
            $update = $runtime['update'];
            $message = trim((string) ($update['message'] ?? ''));
            $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? '';
            if (preg_match('/(authorization|bearer|password|token|secret|private[_-]?key|api[_-]?key|credential)/i', $message)) {
                $message = '[REDACTED]';
            }
            $normalized['update'] = [
                'request_id' => (string) ($update['request_id'] ?? ''),
                'target' => (string) ($update['target'] ?? 'latest'),
                'status' => (string) ($update['status'] ?? ''),
                'updated_at' => (int) ($update['updated_at'] ?? 0),
                'message' => mb_substr($message, 0, 160),
            ];
        }
        return $normalized;
    }
}
