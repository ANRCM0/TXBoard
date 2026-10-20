<?php

namespace App\Domains\Network;

use App\Models\ServerRoute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Full ordering and compatibility mutations share one stable namespace lock. */
final class NativeRouteOrdering
{
    public static function lock(): void
    {
        if (DB::transactionLevel() === 0) throw new \LogicException('ROUTE_ORDERING_TRANSACTION_REQUIRED');
        // Unique insertion serializes the initially empty namespace as well as later mutations.
        DB::table('tx_native_route_ordering_head')->upsert([['id' => 1]], ['id'], ['id']);
        if (!DB::table('tx_native_route_ordering_head')->where('id', 1)->lockForUpdate()->first()) {
            throw new \LogicException('ROUTE_ORDERING_HEAD_REQUIRED');
        }
    }

    public static function locked(): Collection
    {
        self::lock();
        return ServerRoute::query()->orderBy('id')->limit(501)->lockForUpdate()->get();
    }

    public static function snapshot(Collection $routes): array
    {
        $canonical = $routes->sortBy('id', SORT_REGULAR)->values()->map(static fn (ServerRoute $route): array => [
            'id' => (string) $route->id, 'sort' => $route->sort,
        ])->all();
        $ordered = $routes->sort(static function (ServerRoute $left, ServerRoute $right): int {
            if ($left->sort === null && $right->sort !== null) return 1;
            if ($left->sort !== null && $right->sort === null) return -1;
            return ($left->sort <=> $right->sort) ?: ($left->id <=> $right->id);
        })->values();
        return ['revision' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)),
            'route_ids' => $ordered->map(static fn (ServerRoute $route): string => (string) $route->id)->all()];
    }
}
