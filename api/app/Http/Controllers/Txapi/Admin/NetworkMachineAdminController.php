<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\MachineRuntimeUpdateService;
use App\Services\NodeSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NetworkMachineAdminController
{
    private const MAX_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $query = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_PAGE],
        ]);
        $page = ServerMachine::query()->withCount('servers')->orderBy('id')
            ->paginate((int) ($query['per_page'] ?? 100),
                ['id', 'name', 'notes', 'is_active', 'last_seen_at',
                    'load_status', 'created_at', 'updated_at'], 'page',
                (int) ($query['page'] ?? 1));

        $rows = $page->getCollection()->map(static fn (ServerMachine $machine): array => [
            'id' => (int) $machine->id,
            'name' => $machine->name,
            'notes' => $machine->notes,
            'is_active' => (bool) $machine->is_active,
            'last_seen_at' => $machine->last_seen_at,
            'load_status' => $machine->load_status,
            'servers_count' => (int) $machine->servers_count,
            'created_at' => $machine->created_at,
            'updated_at' => $machine->updated_at,
        ])->all();

        return TxapiResponse::success($request, $rows, [
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ])->header('Cache-Control', 'no-store');
    }

    public function create(Request $request): JsonResponse
    {
        $data = $this->validateFields($request);
        $machine = ServerMachine::query()->create([
            'name' => trim($data['name']),
            'notes' => $data['notes'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'token' => ServerMachine::generateToken(),
        ]);
        return $this->secretResponse($request, [
            'id' => (int) $machine->id,
            'token' => $machine->token,
            'install_command' => $this->installCommand($request, $machine),
        ], 201);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $this->validateFields($request);
        $id = (int) $request->route('id');
        $changed = DB::transaction(function () use ($data, $id): bool {
            $machine = ServerMachine::query()->lockForUpdate()->find($id);
            if (!$machine) return false;
            $machine->name = trim($data['name']);
            if (array_key_exists('notes', $data)) $machine->notes = $data['notes'];
            if (array_key_exists('is_active', $data)) $machine->is_active = $data['is_active'];
            $machine->save();
            return true;
        });
        return $changed ? TxapiResponse::success($request, ['ok' => true]) : $this->missing($request);
    }

    public function credentials(Request $request): JsonResponse
    {
        $machine = ServerMachine::query()->find((int) $request->route('id'));
        if (!$machine) return $this->missing($request);
        // Explicit authenticated POST only: never put credentials in a URL or
        // normal machine list; RequestLog sees no token in the request payload.
        return $this->secretResponse($request, [
            'token' => $machine->token,
            'install_command' => $this->installCommand($request, $machine),
        ]);
    }

    public function rotateToken(Request $request): JsonResponse
    {
        $id = (int) $request->route('id');
        $machine = DB::transaction(function () use ($id): ?ServerMachine {
            $machine = ServerMachine::query()->lockForUpdate()->find($id);
            if (!$machine) return null;
            $machine->token = ServerMachine::generateToken();
            $machine->save();
            return $machine;
        });
        if (!$machine) return $this->missing($request);
        return $this->secretResponse($request, [
            'token' => $machine->token,
            'install_command' => $this->installCommand($request, $machine),
        ]);
    }

    public function delete(Request $request): JsonResponse
    {
        $id = (int) $request->route('id');
        $deleted = DB::transaction(function () use ($id): bool {
            $machine = ServerMachine::query()->lockForUpdate()->find($id);
            if (!$machine) return false;
            Server::query()->where('machine_id', $id)->update(['machine_id' => null]);
            $machine->delete();
            return true;
        });
        if (!$deleted) return $this->missing($request);
        // The obsolete host is gone. Publish an empty association snapshot so
        // the socket registry can stop assigning nodes to this machine.
        NodeSyncService::notifyMachineNodesChanged($id);
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function nodes(Request $request): JsonResponse
    {
        $id = (int) $request->route('id');
        if (!ServerMachine::query()->whereKey($id)->exists()) return $this->missing($request);
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_PAGE],
        ]);
        $page = Server::query()->where('machine_id', $id)
            ->orderBy('sort')->orderBy('id')->paginate(
                (int) ($data['per_page'] ?? 100),
                ['id', 'name', 'type', 'host', 'port', 'show', 'enabled', 'sort'],
                'page', (int) ($data['page'] ?? 1));
        return TxapiResponse::success($request, $page->items(), [
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ])->header('Cache-Control', 'no-store');
    }

    public function history(Request $request): JsonResponse
    {
        $id = (int) $request->route('id');
        if (!ServerMachine::query()->whereKey($id)->exists()) return $this->missing($request);
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:10', 'max:1440'],
            'range_hours' => ['sometimes', 'integer', 'min:1', 'max:24'],
        ]);
        $query = ServerMachineLoadHistory::query()->where('machine_id', $id);
        if (isset($data['range_hours'])) {
            $query->where('recorded_at', '>=', now()->subHours($data['range_hours'])->timestamp);
        }
        $rows = $query->orderByDesc('recorded_at')->orderByDesc('id')
            ->limit((int) ($data['limit'] ?? 60))
            ->get(['id', 'cpu', 'mem_total', 'mem_used', 'disk_total', 'disk_used',
                'net_in_speed', 'net_out_speed', 'recorded_at'])
            ->map(static fn (ServerMachineLoadHistory $row): array => [
                'cpu' => $row->cpu, 'mem_total' => $row->mem_total,
                'mem_used' => $row->mem_used, 'disk_total' => $row->disk_total,
                'disk_used' => $row->disk_used,
                'net_in_speed' => $row->net_in_speed,
                'net_out_speed' => $row->net_out_speed,
                'recorded_at' => $row->recorded_at,
            ])->reverse()->values()->all();
        return TxapiResponse::success($request, $rows)->header('Cache-Control', 'no-store');
    }

    public function updateRuntime(Request $request, MachineRuntimeUpdateService $updates): JsonResponse
    {
        $data = $request->validate(['target' => ['required', 'in:latest']]);
        $machine = ServerMachine::query()->find((int) $request->route('id'));
        if (!$machine) return $this->missing($request);
        try {
            return TxapiResponse::success($request,
                $updates->request($machine, $data['target']))
                ->header('Cache-Control', 'no-store');
        } catch (\InvalidArgumentException $e) {
            return TxapiResponse::error($request, 'MACHINE_RUNTIME_UNAVAILABLE',
                $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return TxapiResponse::error($request, 'MACHINE_RUNTIME_DISPATCH_FAILED',
                'Machine runtime update dispatch failed', 503);
        }
    }

    private function validateFields(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (trim($data['name']) === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name' => 'Machine name is required',
            ]);
        }
        return $data;
    }

    private function missing(Request $request): JsonResponse
    {
        return TxapiResponse::error($request, 'NETWORK_MACHINE_NOT_FOUND',
            'Machine not found', 404);
    }

    private function secretResponse(Request $request, array $data, int $status = 200): JsonResponse
    {
        return TxapiResponse::success($request, $data, [], $status)
            ->header('Cache-Control', 'private, no-store')
            ->header('Pragma', 'no-cache');
    }

    private function installCommand(Request $request, ServerMachine $machine): string
    {
        $panelUrl = rtrim((string) (admin_setting('app_url') ?: $request->getSchemeAndHttpHost()), '/');
        $installerUrl = 'https://raw.githubusercontent.com/PaiMonCai/TX-Node-Installer/main/deploy.sh';
        return sprintf(
            'curl -fsSL %s | sudo bash -s -- install --mode machine --panel-url %s --machine-id %d --token %s',
            escapeshellarg($installerUrl),
            escapeshellarg($panelUrl),
            $machine->id,
            escapeshellarg($machine->token)
        );
    }
}
