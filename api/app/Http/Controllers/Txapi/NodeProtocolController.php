<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Services\ServerService;
use App\Services\TrafficUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NodeProtocolController
{
    public function handshake(Request $request): JsonResponse
    {
        $node = $request->attributes->get('txnode.node');
        return TxapiResponse::success($request, [
            'protocol_version' => 1,
            'node_id' => (int) $node->id,
            'capabilities' => [
                'http_poll', 'etag', 'traffic_batch_v1', 'machine_discovery',
            ],
            'websocket' => ['enabled' => false],
            'settings' => $this->intervals(),
        ]);
    }

    public function config(Request $request): JsonResponse
    {
        $node = $request->attributes->get('txnode.node');
        ServerService::touchNode($node);
        $config = ServerService::buildNodeConfig($node);
        $config['base_config'] = $this->intervals();
        return $this->etagResponse($request, ['protocol_version' => 1, 'node_id' => (int) $node->id, 'config' => $config]);
    }

    public function users(Request $request): JsonResponse
    {
        $node = $request->attributes->get('txnode.node');
        ServerService::touchNode($node);
        $users = collect(ServerService::getAvailableUsers($node))->values()->toArray();
        return $this->etagResponse($request, [
            'protocol_version' => 1,
            'node_id' => (int) $node->id,
            'users' => $users,
        ]);
    }

    public function machineNodes(Request $request): JsonResponse
    {
        $machine = $request->attributes->get('txnode.machine');
        $nodes = ServerService::getMachineNodes($machine)->map(static fn ($node) => [
            'id' => (int) $node->id,
            'type' => (string) $node->type,
            'name' => (string) $node->name,
        ])->values()->all();
        return TxapiResponse::success($request, [
            'protocol_version' => 1,
            'nodes' => $nodes,
            'base_config' => $this->intervals(),
        ]);
    }

    public function machineStatus(Request $request): JsonResponse
    {
        $machine = $request->attributes->get('txnode.machine');
        $params = $request->validate([
            'protocol_version' => ['required', 'integer', 'in:1'],
            'cpu' => ['required', 'numeric', 'min:0', 'max:100'],
            'mem' => ['required', 'array'],
            'mem.total' => ['required', 'integer', 'min:0'],
            'mem.used' => ['required', 'integer', 'min:0'],
            'swap' => ['sometimes', 'array'],
            'swap.total' => ['sometimes', 'integer', 'min:0'],
            'swap.used' => ['sometimes', 'integer', 'min:0'],
            'disk' => ['sometimes', 'array'],
            'disk.total' => ['sometimes', 'integer', 'min:0'],
            'disk.used' => ['sometimes', 'integer', 'min:0'],
        ]);
        if ($params['mem']['used'] > $params['mem']['total']) {
            return TxapiResponse::error($request, 'INVALID_MACHINE_STATUS',
                'Used memory cannot exceed total memory', 422);
        }
        $updatedAt = now()->timestamp;
        $machine->forceFill([
            'last_seen_at' => $updatedAt,
            'load_status' => [
                'cpu' => (float) $params['cpu'],
                'mem' => ['total' => (int) $params['mem']['total'],
                    'used' => (int) $params['mem']['used']],
                'swap' => ['total' => (int) ($params['swap']['total'] ?? 0),
                    'used' => (int) ($params['swap']['used'] ?? 0)],
                'disk' => ['total' => (int) ($params['disk']['total'] ?? 0),
                    'used' => (int) ($params['disk']['used'] ?? 0)],
                'updated_at' => $updatedAt,
            ],
        ])->saveOrFail();
        return TxapiResponse::success($request, [
            'protocol_version' => 1,
            'accepted' => true,
        ]);
    }

    public function report(Request $request): JsonResponse
    {
        $node = $request->attributes->get('txnode.node');
        if (strlen($request->getContent()) > 1048576) {
            return TxapiResponse::error($request, 'REPORT_TOO_LARGE', 'Report limit exceeded', 413);
        }
        $params = $request->validate([
            'protocol_version' => ['required', 'integer', 'in:1'],
            'traffic_batch_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9:_-]{8,80}$/D'],
            'traffic' => ['sometimes', 'array', 'max:10000'],
            'alive' => ['sometimes', 'array'],
            'online' => ['sometimes', 'array'],
            'status' => ['sometimes', 'array'],
            'metrics' => ['sometimes', 'array'],
        ]);
        $traffic = $params['traffic'] ?? [];
        $batch = $params['traffic_batch_id'] ?? null;
        if ($traffic !== [] && !$batch) {
            return TxapiResponse::error($request, 'BATCH_ID_REQUIRED',
                'Traffic reports require a stable batch ID', 422);
        }
        if ($traffic !== []) {
            $valid = TrafficUsage::normalize($traffic, $node->getCurrentRate());
            if (count($valid) !== count($traffic)) {
                return TxapiResponse::error($request, 'INVALID_TRAFFIC',
                    'Traffic entries must be unsigned byte pairs', 422);
            }
        }

        ServerService::touchNode($node);
        if ($traffic !== []) {
            ServerService::processTraffic($node, $traffic, $batch);
        }
        if (!empty($params['alive'])) {
            ServerService::processAlive($node->id, $params['alive']);
        }
        if (!empty($params['online'])) {
            ServerService::processOnline($node, $params['online']);
        }
        if (!empty($params['status'])) {
            ServerService::processStatus($node, $params['status']);
        }
        if (!empty($params['metrics'])) {
            ServerService::updateMetrics($node, $params['metrics']);
        }
        return TxapiResponse::success($request, [
            'protocol_version' => 1,
            'accepted' => true,
            'traffic_batch_id' => $batch,
            'settlement' => $traffic === [] ? 'none' : 'queued',
        ], status: 202);
    }

    private function intervals(): array
    {
        return [
            'push_interval' => (int) admin_setting('server_push_interval', 60),
            'pull_interval' => (int) admin_setting('server_pull_interval', 60),
        ];
    }

    private function etagResponse(Request $request, array $payload): JsonResponse
    {
        $etag = '"'.hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)).'"';
        if (trim((string) $request->header('If-None-Match', '')) === $etag) {
            return response()->json(null, 304)->header('ETag', $etag);
        }
        return TxapiResponse::success($request, $payload)->header('ETag', $etag);
    }
}
