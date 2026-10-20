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
        $reader = app(\App\Domains\Network\Contracts\NetworkGroups::class);
        $page = $request->attributes->has('native_internal_operation')
            ? $reader->read((int) $request->input('page', 1), (int) $request->input('per_page', 20))
            : $reader->read();
        return TxapiResponse::success($request, $page['items'], $page['pagination'] ?? [])
            ->header('Cache-Control', 'no-store');
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

    public function names(Request $request): JsonResponse
    {
        $input = $request->validate(['group_ids' => ['present', 'array', 'max:100'],
            'group_ids.*' => ['required', 'integer', 'min:1', 'distinct']]);
        $groups = ServerGroup::query()->whereIn('id', $input['group_ids'])->orderBy('id')->get(['id', 'name'])
            ->map(static fn (ServerGroup $group): array => ['id' => (string) $group->id, 'name' => $group->name])->all();
        return TxapiResponse::success($request, ['groups' => $groups])->header('Cache-Control', 'no-store');
    }

    public function delete(Request $request): JsonResponse
    {
        // The dynamic {admin_path} is the first route parameter; read id by
        // name rather than relying on parameter position.
        $id = (int) $request->route('id');
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
