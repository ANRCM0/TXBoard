<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Installation\InstallationAuthority;
use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NativeManagementReadController
{
    public function node(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            app(InstallationAuthority::class)->assertNativeRead();
            $node = Server::query()->useWritePdo()->whereKey($request->input('node_id'))->sharedLock()->first([
                'id', 'name', 'type', 'host', 'port', 'server_port', 'parent_id', 'machine_id',
                'show', 'enabled', 'sort', 'u', 'd', 'group_ids', 'route_ids', 'tags', 'created_at', 'updated_at',
            ]);
            if ($node === null) abort(404);
            $data = $node->only(['id', 'name', 'type', 'host', 'port', 'server_port', 'parent_id', 'machine_id',
                'show', 'enabled', 'sort', 'u', 'd', 'created_at', 'updated_at']);
            $data['show'] = (bool) $node->show;
            $data['enabled'] = (bool) $node->enabled;
            foreach (['group_ids', 'route_ids'] as $key) $data[$key] = array_map('strval', $node->$key ?? []);
            $data['tags'] = array_values($node->tags ?? []);
            return TxapiResponse::success($request, $data);
        });
    }

    public function machine(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request): JsonResponse {
            app(InstallationAuthority::class)->assertNativeRead();
            $machine = ServerMachine::query()->useWritePdo()->withCount('servers')
                ->whereKey($request->input('machine_id'))->sharedLock()->first([
                    'id', 'name', 'notes', 'is_active', 'image_channel', 'last_seen_at', 'created_at', 'updated_at',
                ]);
            if ($machine === null) abort(404);
            return TxapiResponse::success($request, [
                'id' => (string) $machine->id, 'name' => $machine->name, 'notes' => $machine->notes,
                'is_active' => (bool) $machine->is_active, 'image_channel' => $machine->image_channel ?: 'stable',
                'last_seen_at' => $machine->last_seen_at, 'servers_count' => (int) $machine->servers_count,
                'created_at' => $machine->created_at, 'updated_at' => $machine->updated_at,
            ]);
        });
    }
}
