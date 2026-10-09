<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Http\Requests\Txapi\Admin\NodeSaveRequest;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Protocols\ProtocolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NetworkNodeAdminController
{
    private const MAX_PER_PAGE = 100;
    private const MAX_BATCH = 100;

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
        ]);
        $perPage = (int) ($input['per_page'] ?? 100);
        $page = Server::query()->with('parent:id,name')
            ->orderBy('sort')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', (int) ($input['page'] ?? 1));
        $ids = $page->getCollection()->flatMap(
            static fn (Server $node): array => $node->group_ids ?? []
        )->unique()->values()->all();
        $groups = $ids === [] ? collect() :
            ServerGroup::query()->whereIn('id', $ids)->get(['id', 'name'])->keyBy('id');
        $items = $page->getCollection()->map(fn (Server $node): array =>
            $this->project($node, $groups)
        )->all();

        return TxapiResponse::success($request, $items, [
            'page' => $page->currentPage(), 'per_page' => $perPage,
            'total' => $page->total(), 'last_page' => $page->lastPage(),
        ])->header('Cache-Control', 'no-store');
    }

    public function protocols(Request $request, ProtocolRegistry $registry): JsonResponse
    {
        return TxapiResponse::success($request, $registry->metadata())
            ->header('Cache-Control', 'no-store');
    }

    public function save(NodeSaveRequest $request): JsonResponse
    {
        $data = $request->validated();
        $node = DB::transaction(function () use ($data): Server {
            $this->assertParentChain($data['parent_id'] ?? null, null);
            return Server::query()->create($data);
        });
        return TxapiResponse::success($request, ['ok' => true, 'id' => (int) $node->id]);
    }

    public function replace(NodeSaveRequest $request): JsonResponse
    {
        $id = (int) $request->route('id');
        $data = $request->validated();
        $updated = DB::transaction(function () use ($data, $id): bool {
            $node = Server::query()->lockForUpdate()->find($id);
            if (!$node) return false;
            $this->assertParentChain($data['parent_id'] ?? null, $id);
            $node->update($data);
            return true;
        });
        if (!$updated) return $this->notFound($request);
        return TxapiResponse::success($request, ['ok' => true, 'id' => $id]);
    }

    public function patch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'show' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
            'machine_id' => ['sometimes', 'nullable', 'integer', 'exists:v2_server_machine,id'],
        ]);
        if ($data === []) {
            return TxapiResponse::error($request, 'NETWORK_NODE_UPDATE_EMPTY',
                'No editable fields supplied', 422);
        }
        $id = (int) $request->route('id');
        $updated = DB::transaction(function () use ($id, $data): bool {
            $node = Server::query()->lockForUpdate()->find($id);
            if (!$node) return false;
            $node->update($data);
            return true;
        });
        if (!$updated) return $this->notFound($request);
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function copy(Request $request): JsonResponse
    {
        $id = (int) $request->route('id');
        $newId = DB::transaction(function () use ($id): ?int {
            $node = Server::query()->lockForUpdate()->find($id);
            if (!$node) return null;
            $clone = $node->replicate();
            $clone->show = false;
            $clone->code = null;
            $clone->u = 0;
            $clone->d = 0;
            $clone->save();
            return (int) $clone->id;
        });
        if ($newId === null) return $this->notFound($request);
        return TxapiResponse::success($request, $newId);
    }

    public function delete(Request $request): JsonResponse
    {
        $id = (int) $request->route('id');
        $deleted = DB::transaction(function () use ($id): string {
            $node = Server::query()->lockForUpdate()->find($id);
            if (!$node) return 'missing';
            if (Server::query()->where('parent_id', $id)->exists()) return 'in_use';
            $node->delete(); // Eloquent observer schedules machine registry sync.
            return 'ok';
        });
        if ($deleted === 'missing') return $this->notFound($request);
        if ($deleted === 'in_use') return TxapiResponse::error($request, 'NETWORK_NODE_HAS_CHILDREN',
            'Detach child nodes before deletion', 409);
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function sort(Request $request): JsonResponse
    {
        $rows = $request->validate([
            '*.id' => ['required', 'integer', 'min:1'],
            '*.order' => ['required', 'integer', 'min:0'],
        ]);
        if (!$this->validRows($rows)) return $this->invalidBatch($request);
        $ok = DB::transaction(function () use ($rows): bool {
            $nodes = Server::query()->whereIn('id', array_column($rows, 'id'))
                ->lockForUpdate()->get()->keyBy('id');
            if ($nodes->count() !== count($rows)) return false;
            foreach ($rows as $row) {
                $node = $nodes->get($row['id']);
                $node->sort = $row['order'];
                $node->save();
            }
            return true;
        });
        return $ok ? TxapiResponse::success($request, ['ok' => true]) : $this->notFound($request);
    }

    public function batchUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:' . self::MAX_BATCH],
            'ids.*' => ['integer', 'min:1', 'distinct'],
            'show' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
            'machine_id' => ['sometimes', 'nullable', 'integer', 'exists:v2_server_machine,id'],
        ]);
        $ids = $data['ids'];
        unset($data['ids']);
        if ($data === []) return TxapiResponse::error($request, 'NETWORK_NODE_UPDATE_EMPTY',
            'No editable fields supplied', 422);
        $ok = DB::transaction(function () use ($ids, $data): bool {
            $nodes = Server::query()->whereIn('id', $ids)->lockForUpdate()->get();
            if ($nodes->count() !== count($ids)) return false;
            foreach ($nodes as $node) $node->update($data);
            return true;
        });
        return $ok ? TxapiResponse::success($request, ['ok' => true]) : $this->notFound($request);
    }

    public function batchDelete(Request $request): JsonResponse
    {
        $ids = $this->validateIds($request);
        $status = DB::transaction(function () use ($ids): string {
            $nodes = Server::query()->whereIn('id', $ids)->lockForUpdate()->get();
            if ($nodes->count() !== count($ids)) return 'missing';
            // Reject any references, including children within the same batch:
            // deletion order must never produce implicit cascade semantics.
            if (Server::query()->whereIn('parent_id', $ids)->exists()) return 'in_use';
            foreach ($nodes as $node) $node->delete();
            return 'ok';
        });
        if ($status === 'missing') return $this->notFound($request);
        if ($status === 'in_use') return TxapiResponse::error($request, 'NETWORK_NODE_HAS_CHILDREN',
            'Detach child nodes before batch deletion', 409);
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function resetTraffic(Request $request): JsonResponse
    {
        $id = (int) $request->route('id');
        $ok = DB::transaction(function () use ($id): bool {
            $node = Server::query()->lockForUpdate()->find($id);
            if (!$node) return false;
            $node->u = 0;
            $node->d = 0;
            $node->save();
            return true;
        });
        return $ok ? TxapiResponse::success($request, ['ok' => true]) : $this->notFound($request);
    }

    public function batchResetTraffic(Request $request): JsonResponse
    {
        $ids = $this->validateIds($request);
        $ok = DB::transaction(function () use ($ids): bool {
            $nodes = Server::query()->whereIn('id', $ids)->lockForUpdate()->get();
            if ($nodes->count() !== count($ids)) return false;
            foreach ($nodes as $node) {
                $node->u = 0;
                $node->d = 0;
                $node->save();
            }
            return true;
        });
        return $ok ? TxapiResponse::success($request, ['ok' => true]) : $this->notFound($request);
    }

    private function validateIds(Request $request): array
    {
        return $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:' . self::MAX_BATCH],
            'ids.*' => ['integer', 'min:1', 'distinct'],
        ])['ids'];
    }

    private function validRows(array $rows): bool
    {
        if (count($rows) === 0 || count($rows) > self::MAX_BATCH) return false;
        $ids = array_column($rows, 'id');
        return count($ids) === count(array_unique($ids));
    }

    private function invalidBatch(Request $request): JsonResponse
    {
        return TxapiResponse::error($request, 'NETWORK_NODE_BATCH_INVALID',
            'Batch must contain unique IDs (1-100 entries)', 422);
    }

    private function notFound(Request $request): JsonResponse
    {
        return TxapiResponse::error($request, 'NETWORK_NODE_NOT_FOUND', 'Node not found', 404);
    }

    private function assertParentChain(?int $parentId, ?int $nodeId): void
    {
        $seen = [];
        while ($parentId !== null) {
            if ($parentId === $nodeId || isset($seen[$parentId])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'parent_id' => 'Parent graph must not contain a cycle',
                ]);
            }
            $seen[$parentId] = true;
            $parentId = Server::query()->whereKey($parentId)->value('parent_id');
        }
    }

    private function project(Server $node, $groups): array
    {
        $fields = [
            'id','name','type','host','port','server_port','group_ids','route_ids',
            'tags','excludes','ips','parent_id','machine_id','show','enabled',
            'rate','rate_time_enable','rate_time_ranges','protocol_settings',
            'transfer_enable','custom_outbounds','custom_routes','cert_config',
            'code','spectific_key','sort','u','d','created_at','updated_at',
        ];
        $data = [];
        foreach ($fields as $field) $data[$field] = $node->getAttribute($field);
        $data['groups'] = collect($node->group_ids ?? [])
            ->map(static fn ($id) => $groups->get((int) $id))
            ->filter()->map(static fn (ServerGroup $group) => [
                'id' => $group->id, 'name' => $group->name,
            ])->values()->all();
        $data['parent'] = $node->parent ? [
            'id' => $node->parent->id, 'name' => $node->parent->name,
        ] : null;
        foreach (['last_check_at','last_push_at','online','is_online',
            'available_status','load_status','metrics','online_conn'] as $field) {
            $data[$field] = $node->getAttribute($field);
        }
        return $data;
    }
}
