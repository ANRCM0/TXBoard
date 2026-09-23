<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\ServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * machine controller
 */
class MachineController extends Controller
{
    /**
     * get nodes list for machine
     */
    public function nodes(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request);

        $nodes = ServerService::getMachineNodes($machine)
            ->map(fn($node) => [
                'id' => $node->id,
                'type' => $node->type,
                'name' => $node->name,
            ])->values();

        return response()->json([
            'nodes' => $nodes,
            'base_config' => [
                'push_interval' => (int) admin_setting('server_push_interval', 60),
                'pull_interval' => (int) admin_setting('server_pull_interval', 60),
            ],
        ]);
    }

    /**
     * report machine status
     */
    public function status(Request $request): JsonResponse
    {
        $params = $request->validate([
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
            'runtime.update.target' => 'nullable|in:latest',
            'runtime.update.status' => 'nullable|in:accepted,running,succeeded,failed,rolled_back',
            'runtime.update.updated_at' => 'nullable|integer|min:1',
            'runtime.update.message' => 'nullable|string|max:160',
        ]);

        $machine = $this->authenticateMachine($request);
        $recordedAt = now()->timestamp;

        $loadStatus = [
            'cpu' => (float) $request->input('cpu'),
            'mem' => [
                'total' => (int) $request->input('mem.total'),
                'used' => (int) $request->input('mem.used'),
            ],
            'swap' => [
                'total' => (int) $request->input('swap.total', 0),
                'used' => (int) $request->input('swap.used', 0),
            ],
            'disk' => [
                'total' => (int) $request->input('disk.total', 0),
                'used' => (int) $request->input('disk.used', 0),
            ],
            'updated_at' => $recordedAt,
        ];

        $netInSpeed = $request->input('net.in_speed');
        $netOutSpeed = $request->input('net.out_speed');

        if ($netInSpeed !== null && $netOutSpeed !== null) {
            $loadStatus['net'] = [
                'in_speed' => (float) $netInSpeed,
                'out_speed' => (float) $netOutSpeed,
            ];
        }

        if (isset($params['runtime']) && is_array($params['runtime'])) {
            $loadStatus['runtime'] = $this->normalizeRuntimeStatus($params['runtime']);
        }

        $machine->forceFill([
            'load_status' => $loadStatus,
            'last_seen_at' => $recordedAt,
        ])->save();

        $historyData = [
            'machine_id' => $machine->id,
            'cpu' => (float) $request->input('cpu'),
            'mem_total' => (int) $request->input('mem.total'),
            'mem_used' => (int) $request->input('mem.used'),
            'disk_total' => (int) $request->input('disk.total', 0),
            'disk_used' => (int) $request->input('disk.used', 0),
            'recorded_at' => $recordedAt,
        ];

        if ($netInSpeed !== null && $netOutSpeed !== null) {
            $historyData['net_in_speed'] = (float) $netInSpeed;
            $historyData['net_out_speed'] = (float) $netOutSpeed;
        }

        ServerMachineLoadHistory::create($historyData);

        // Time-based cleanup: keep 24h of data, runs on ~5% of requests
        if (random_int(1, 20) === 1) {
            ServerMachineLoadHistory::query()
                ->where('machine_id', $machine->id)
                ->where('recorded_at', '<', now()->subDay()->timestamp)
                ->delete();
        }

        return response()->json(['data' => true]);
    }

    private function normalizeRuntimeStatus(array $runtime): array
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
            $message = preg_replace('/[\\x00-\\x1F\\x7F]+/u', ' ', $message) ?? '';
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

    private function authenticateMachine(Request $request): ServerMachine
    {
        $request->validate([
            'machine_id' => 'required|integer',
            'token' => 'required|string',
        ]);

        $machine = ServerMachine::where('id', $request->input('machine_id'))
            ->where('token', $request->input('token'))
            ->first();

        if (!$machine || !$machine->is_active) {
            abort(403, 'Machine not found or disabled');
        }

        $machine->forceFill(['last_seen_at' => now()->timestamp])->saveQuietly();

        return $machine;
    }
}
