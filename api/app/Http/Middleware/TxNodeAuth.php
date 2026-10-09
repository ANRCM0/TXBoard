<?php

namespace App\Http\Middleware;

use App\Core\Http\TxapiResponse;
use App\Models\Server;
use App\Models\ServerMachine;
use Closure;
use Illuminate\Http\Request;

/**
 * Native TX-Node v1 uses bearer credentials and explicit identity headers.
 * No token in query strings, JSON bodies or application logs.
 */
final class TxNodeAuth
{
    public function handle(Request $request, Closure $next)
    {
        $credential = $request->bearerToken();
        if (!is_string($credential) || $credential === '' || strlen($credential) > 512) {
            return TxapiResponse::error($request, 'NODE_UNAUTHORIZED', 'Invalid node credentials', 401);
        }
        $machineId = $this->positiveId($request->header('X-TX-Machine-ID'));
        $nodeId = $this->positiveId($request->header('X-TX-Node-ID'));
        $machineOnly = $request->is('txapi/node/v1/machine/nodes')
            || $request->is('txapi/node/v1/machine/status')
            || ($request->is('txapi/node/v1/handshake')
                && $machineId !== null && $nodeId === null);

        if ($request->hasHeader('X-TX-Node-ID') && $nodeId === null) {
            return TxapiResponse::error($request, 'NODE_ID_REQUIRED', 'Invalid node identity', 422);
        }
        if ($request->hasHeader('X-TX-Machine-ID') && $machineId === null) {
            return TxapiResponse::error($request, 'NODE_UNAUTHORIZED', 'Invalid machine identity', 401);
        }
        if ($machineId !== null) {
            $machine = ServerMachine::query()->find($machineId);
            if (!$machine || !$machine->is_active ||
                !is_string($machine->token) || !hash_equals($machine->token, $credential)) {
                return TxapiResponse::error($request, 'NODE_UNAUTHORIZED', 'Invalid node credentials', 401);
            }
            $request->attributes->set('txnode.machine', $machine);
            if (!$machineOnly) {
                if ($nodeId === null) {
                    return TxapiResponse::error($request, 'NODE_ID_REQUIRED', 'Node identity required', 422);
                }
                $node = Server::query()->whereKey($nodeId)
                    ->where('machine_id', $machineId)->where('enabled', true)->first();
                if (!$node) {
                    return TxapiResponse::error($request, 'NODE_NOT_FOUND', 'Node unavailable', 404);
                }
                $request->attributes->set('txnode.node', $node);
            }
        } else {
            if ($machineOnly) {
                return TxapiResponse::error($request, 'NODE_UNAUTHORIZED', 'Machine identity required', 401);
            }
            $configured = (string) admin_setting('server_token', '');
            if ($configured === '' || !hash_equals($configured, $credential)) {
                return TxapiResponse::error($request, 'NODE_UNAUTHORIZED', 'Invalid node credentials', 401);
            }
            if ($nodeId === null) {
                return TxapiResponse::error($request, 'NODE_ID_REQUIRED', 'Node identity required', 422);
            }
            $node = Server::query()->whereKey($nodeId)->where('enabled', true)->first();
            if (!$node) {
                return TxapiResponse::error($request, 'NODE_NOT_FOUND', 'Node unavailable', 404);
            }
            $request->attributes->set('txnode.node', $node);
        }
        return $next($request);
    }

    private function positiveId(?string $value): ?int
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,17}$/D', $value)) {
            return null;
        }
        $number = (int) $value;
        return $number > 0 ? $number : null;
    }
}
