<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Services\ServerService;
use App\Domains\Network\MachineTelemetry;
use App\Domains\Network\NativeNodeReport;
use App\Domains\Network\NativeAccessAudit;
use App\Domains\Network\NodeReportError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NodeProtocolController
{
    public function handshake(Request $request): JsonResponse
    {
        $node = $request->attributes->get('txnode.node');
        return TxapiResponse::success($request, [
            'protocol_version' => 1,
            'node_id' => $node ? (int) $node->id : null,
            'mode' => $node ? 'node' : 'machine',
            'capabilities' => [
                'http_poll', 'etag', 'traffic_batch_v1', 'machine_discovery', 'access_audit_v1',
            ],
            'websocket' => [
                'enabled' => (bool) config('node_ws.native_enabled', false),
                'path' => '/txapi/node/v1/ws',
                'heartbeat_interval_seconds' => 55,
            ],
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
        ] + MachineTelemetry::rules());
        if ($params['mem']['used'] > $params['mem']['total']) {
            return TxapiResponse::error($request, 'INVALID_MACHINE_STATUS',
                'Used memory cannot exceed total memory', 422);
        }
        app(MachineTelemetry::class)->record($machine, $params);
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
        try {
            $result = app(NativeNodeReport::class)->submit($node, $request->all());
        } catch (NodeReportError $e) {
            return TxapiResponse::error($request, $e->errorCode, $e->getMessage(), 422);
        }
        return TxapiResponse::success($request, $result, status: 202);
    }

    public function auditRules(Request $request): JsonResponse
    {
        return TxapiResponse::success($request, [
            'protocol_version' => 1,
            'rules' => app(NativeAccessAudit::class)->rules(),
        ])->header('Cache-Control', 'no-store');
    }

    public function auditReport(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 1048576) {
            return TxapiResponse::error($request, 'AUDIT_TOO_LARGE', 'Audit report limit exceeded', 413);
        }
        $node = $request->attributes->get('txnode.node');
        return TxapiResponse::success($request,
            app(NativeAccessAudit::class)->ingest($node, $request->all())
        )->header('Cache-Control', 'no-store');
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
