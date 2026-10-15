<?php

namespace App\Http\Controllers\Txapi;

use App\Core\Http\TxapiResponse;
use App\Services\ServerService;
use App\Domains\Network\MachineTelemetry;
use App\Domains\Network\NativeNodeAuthorization;
use App\Domains\Network\NativeNodeReport;
use App\Domains\Network\NativeAccessAudit;
use App\Domains\Network\NodeReportError;
use App\Domains\UsageV2\UsageRegistry;
use App\Domains\UsageV2\UsageError;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NodeProtocolController
{
    public function handshake(Request $request): JsonResponse
    {
        $node = $request->attributes->get('txnode.node');
        $data = [
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
        ];
        $authorization = app(NativeNodeAuthorization::class);
        if ($authorization->enabled()) {
            try {
                $data['capabilities'][] = NativeNodeAuthorization::CAPABILITY;
                $data['authorization_trust'] = $authorization->publicTrust();
                $data['authorization_refresh_interval_seconds'] = 30;
            } catch (\DomainException) {
                return TxapiResponse::error($request, 'NODE_AUTHORIZATION_UNAVAILABLE',
                    'Node authorization is temporarily unavailable', 503)->header('Cache-Control', 'no-store');
            }
        }
        if (config('native_traffic_receipts.enabled') === true) {
            $data['capabilities'][] = 'traffic_receipt_durable_v1';
            if (config('native_traffic_periods.enabled', true) === true) $data['capabilities'][] = 'traffic_period_v1';
        }
        if ($node) {
            try {
                $capability = app(UsageRegistry::class)->capability((int) $node->id, time());
                if ($capability !== null) { $data['usage_v2'] = $capability; }
            } catch (UsageError $error) {
                // Optional isolated capability cannot interrupt the authoritative v1 handshake.
                $data['usage_error'] = ['code' => $error->errorCode];
            }
        }
        return TxapiResponse::success($request, $data);
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
        $authorization = app(NativeNodeAuthorization::class);
        if ($authorization->enabled()) {
            try {
                $data = DB::transaction(function () use ($node, $authorization) {
                    $locked = Server::query()->whereKey($node->id)->lockForUpdate()->first();
                    if (!$locked || !$locked->enabled || $locked->machine_id !== $node->machine_id) {
                        throw new \DomainException('NATIVE_NODE_AUTHORIZATION_TARGET_INVALID');
                    }
                    $users = collect(ServerService::getNativeAvailableUsers($locked))->values()->toArray();
                    return ['protocol_version' => 1, 'node_id' => (int) $node->id, 'users' => $users,
                        'authorization' => $authorization->issueLocked($locked, $users, time())];
                }, 3);
                return TxapiResponse::success($request, $data)->header('Cache-Control', 'no-store, private');
            } catch (\Throwable) {
                return TxapiResponse::error($request, 'NODE_AUTHORIZATION_UNAVAILABLE',
                    'Node authorization is temporarily unavailable', 503)->header('Cache-Control', 'no-store, private');
            }
        }
        $users = collect(ServerService::getNativeAvailableUsers($node))->values()->toArray();
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
            return TxapiResponse::error($request, $e->errorCode, $e->getMessage(), match ($e->errorCode) {
                'TRAFFIC_BATCH_CONFLICT' => 409, 'TRAFFIC_DURABILITY_UNAVAILABLE' => 503, default => 422,
            });
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
