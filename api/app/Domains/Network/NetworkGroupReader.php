<?php

namespace App\Domains\Network;

use App\Models\Server;
use App\Models\ServerGroup;

/** Shared native projection; pagination adds no new network authority. */
final class NetworkGroupReader
{
    public function read(?int $page = null, ?int $perPage = null): array
    {
        $counts = [];
        Server::query()->select(['id', 'group_ids'])->orderBy('id')
            ->chunkById(250, function ($nodes) use (&$counts): void {
                foreach ($nodes as $node) {
                    foreach (array_unique(array_map('intval', $node->group_ids ?? [])) as $id) {
                        if ($id > 0) $counts[$id] = ($counts[$id] ?? 0) + 1;
                    }
                }
            });
        $query = ServerGroup::query()->withCount(['users', 'plans'])->orderByDesc('id');
        $pagination = null;
        if ($page === null) {
            $groups = $query->get(['id', 'name', 'created_at', 'updated_at']);
        } else {
            $paginator = $query->paginate($perPage, ['id', 'name', 'created_at', 'updated_at'], 'page', $page);
            $groups = $paginator->getCollection();
            $pagination = ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(),
                'total' => $paginator->total(), 'last_page' => $paginator->lastPage()];
        }
        $items = $groups->map(static fn (ServerGroup $group): array => [
            'id' => $group->id, 'name' => $group->name, 'users_count' => $group->users_count,
            'plans_count' => $group->plans_count, 'server_count' => $counts[$group->id] ?? 0,
            'created_at' => $group->created_at, 'updated_at' => $group->updated_at,
        ])->all();
        return ['items' => $items, 'pagination' => $pagination];
    }
}
