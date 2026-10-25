<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Network\NativeNodeConnections;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NativeNodeConnectionsController
{
    public function listConnections(Request $request, NativeNodeConnections $owner): JsonResponse
    {
        return $this->submit($request, $owner, 'list');
    }

    public function closeConnection(Request $request, NativeNodeConnections $owner): JsonResponse
    {
        return $this->submit($request, $owner, 'close');
    }

    private function submit(Request $request, NativeNodeConnections $owner, string $action): JsonResponse
    {
        $node = Server::query()->find($request->input('node_id'));
        if (!$node) return $this->missing($request);
        try {
            $view = $owner->request($node, (int) $request->user()->id, $action, $request->all());
            return TxapiResponse::success($request, $view)->header('Cache-Control', 'private, no-store');
        } catch (\DomainException $error) {
            return TxapiResponse::error($request, $error->getMessage(), 'Node connection operation is unavailable',
                $error->getMessage() === 'NODE_CONNECTIONS_RATE_LIMITED' ? 429 : 503)->header('Cache-Control', 'private, no-store');
        }
    }

    public function result(Request $request, NativeNodeConnections $owner): JsonResponse
    {
        $node = Server::query()->find($request->input('node_id'));
        if (!$node) return $this->missing($request);
        $view = $owner->read($node, (int) $request->user()->id, $request->input('request_id'));
        if ($view === null) return $this->missing($request);
        return TxapiResponse::success($request, $view)->header('Cache-Control', 'private, no-store');
    }

    private function missing(Request $request): JsonResponse
    {
        return TxapiResponse::error($request, 'NODE_CONNECTIONS_NOT_FOUND', 'Node or connection request not found', 404)
            ->header('Cache-Control', 'private, no-store');
    }
}
