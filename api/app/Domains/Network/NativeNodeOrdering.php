<?php

namespace App\Domains\Network;

use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Membership and ordering changes acquire this lock before any node row lock. */
final class NativeNodeOrdering
{
    public static function lock(): void
    {
        if (DB::transactionLevel() === 0) throw new \LogicException('NODE_ORDERING_TRANSACTION_REQUIRED');
        DB::table('tx_native_node_ordering_head')->upsert([['id' => 1]], ['id'], ['id']);
        if (!DB::table('tx_native_node_ordering_head')->where('id', 1)->lockForUpdate()->first()) {
            throw new \LogicException('NODE_ORDERING_HEAD_REQUIRED');
        }
    }

    public static function locked(): Collection
    {
        self::lock();
        // One sentinel row distinguishes an unsupported full set from a truncated set.
        return Server::query()->orderBy('id')->limit(101)->lockForUpdate()->get();
    }

    public static function snapshot(Collection $nodes): array
    {
        $canonical = $nodes->sortBy('id', SORT_REGULAR)->values()->map(static fn (Server $node): array => [
            'id' => (string) $node->id, 'sort' => $node->sort,
        ])->all();
        $ordered = $nodes->sort(static function (Server $left, Server $right): int {
            if ($left->sort === null && $right->sort !== null) return -1;
            if ($left->sort !== null && $right->sort === null) return 1;
            return ($left->sort <=> $right->sort) ?: ($left->id <=> $right->id);
        })->values();
        return ['revision' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)),
            'nodes' => $ordered->map(static fn (Server $node): array => [
                'id' => (string) $node->id, 'name' => $node->name, 'type' => $node->type,
            ])->all()];
    }
}
