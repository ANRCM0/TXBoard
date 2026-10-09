<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NetworkGroupAdminController
{
    public function index(Request $request): JsonResponse
    {
        // Count node memberships with one bounded projection rather than one
        // JSON query for every group (the old V2 controller had N+1 counts).
        $counts = [];
        Server::query()->select(['id', 'group_ids'])->orderBy('id')
            ->chunkById(250, function ($nodes) use (&$counts): void {
                foreach ($nodes as $node) {
                    foreach (array_unique(array_map('intval', $node->group_ids ?? [])) as $id) {
                        if ($id > 0) $counts[$id] = ($counts[$id] ?? 0) + 1;
                    }
                }
            });

        $groups = ServerGroup::query()->withCount(['users', 'plans'])
            ->orderByDesc('id')->get(['id', 'name', 'created_at', 'updated_at']);
        $data = $groups->map(static fn (ServerGroup $group): array => [
            'id' => $group->id,
            'name' => $group->name,
            'users_count' => $group->users_count,
            'plans_count' => $group->plans_count,
            'server_count' => $counts[$group->id] ?? 0,
            'created_at' => $group->created_at,
            'updated_at' => $group->updated_at,
        ])->all();

        return TxapiResponse::success($request, $data)->header('Cache-Control', 'no-store');
    }

    public function save(Request $request): JsonResponse
    {
        $input = $request->validate([
            'id' => ['sometimes', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $name = trim($input['name']);
        if ($name === '') {
            return TxapiResponse::error($request, 'NETWORK_GROUP_NAME_REQUIRED', 'Group name is required', 422);
        }

        if (isset($input['id'])) {
            $group = ServerGroup::query()->find($input['id']);
            if (!$group) {
                return TxapiResponse::error($request, 'NETWORK_GROUP_NOT_FOUND', 'Group not found', 404);
            }
            $group->name = $name;
            $group->save();
        } else {
            $group = new ServerGroup();
            $group->name = $name;
            $group->save();
        }

        return TxapiResponse::success($request, ['id' => (int) $group->id, 'ok' => true]);
    }

    public function delete(Request $request, int $id): JsonResponse
    {
        $deleted = DB::transaction(function () use ($id): string {
            $group = ServerGroup::query()->lockForUpdate()->find($id);
            if (!$group) return 'missing';
            if (Plan::query()->where('group_id', $id)->exists()
                || User::query()->where('group_id', $id)->exists()
                || Server::query()->where(function ($query) use ($id) {
                    $query->whereJsonContains('group_ids', $id)
                        ->orWhereJsonContains('group_ids', (string) $id);
                })->exists()) return 'in_use';
            $group->delete();
            return 'ok';
        });
        if ($deleted === 'missing') return TxapiResponse::error($request, 'NETWORK_GROUP_NOT_FOUND', 'Group not found', 404);
        if ($deleted === 'in_use') return TxapiResponse::error($request, 'NETWORK_GROUP_IN_USE',
            'Group is still referenced by users, plans or nodes', 409);
        return TxapiResponse::success($request, ['ok' => true]);
    }
}
