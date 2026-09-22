<?php

namespace App\Services\AgentOps;

use App\Models\Server;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AgentTargetScope
{
    public const RESTRICTED = 'agent:target:restricted';
    public const NODE_PREFIX = 'agent:target:node:';
    public const MACHINE_PREFIX = 'agent:target:machine:';

    public static function compile(
        array $functionalAbilities,
        string $mode = 'all',
        array $nodeIds = [],
        array $machineIds = [],
    ): array {
        if ($mode === 'all') {
            return array_values(array_unique($functionalAbilities));
        }

        if ($mode !== 'restricted') {
            throw new \InvalidArgumentException('Unknown target scope mode');
        }

        $nodeIds = self::normalizeIds($nodeIds);
        $machineIds = self::normalizeIds($machineIds);
        if ($nodeIds === [] && $machineIds === []) {
            throw new \InvalidArgumentException('Restricted target scope requires at least one node or machine');
        }

        $abilities = [...$functionalAbilities, self::RESTRICTED];
        foreach ($nodeIds as $id) {
            $abilities[] = self::NODE_PREFIX . $id;
        }
        foreach ($machineIds as $id) {
            $abilities[] = self::MACHINE_PREFIX . $id;
        }

        return array_values(array_unique($abilities));
    }

    public static function describe(object $token): array
    {
        $abilities = array_map('strval', $token->abilities ?? []);
        if (!in_array(self::RESTRICTED, $abilities, true)) {
            return ['mode' => 'all', 'node_ids' => [], 'machine_ids' => []];
        }

        return [
            'mode' => 'restricted',
            'node_ids' => self::idsWithPrefix($abilities, self::NODE_PREFIX),
            'machine_ids' => self::idsWithPrefix($abilities, self::MACHINE_PREFIX),
        ];
    }

    public static function functionalAbilities(object $token): array
    {
        return array_values(array_filter(
            array_map('strval', $token->abilities ?? []),
            static fn (string $ability) => !str_starts_with($ability, 'agent:target:')
        ));
    }

    public static function allowedNodeIds(object $token): ?array
    {
        $scope = self::describe($token);
        if ($scope['mode'] === 'all') {
            return null;
        }

        $nodeIds = $scope['node_ids'];
        if ($scope['machine_ids'] !== []) {
            $nodeIds = array_merge(
                $nodeIds,
                Server::query()
                    ->whereIn('machine_id', $scope['machine_ids'])
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all()
            );
        }

        return self::normalizeIds($nodeIds);
    }

    public static function allowedMachineIds(object $token): ?array
    {
        $scope = self::describe($token);
        if ($scope['mode'] === 'all') {
            return null;
        }

        $machineIds = $scope['machine_ids'];
        if ($scope['node_ids'] !== []) {
            $machineIds = array_merge(
                $machineIds,
                Server::query()
                    ->whereIn('id', $scope['node_ids'])
                    ->whereNotNull('machine_id')
                    ->pluck('machine_id')
                    ->map(fn ($id) => (int) $id)
                    ->all()
            );
        }

        return self::normalizeIds($machineIds);
    }

    public static function assertNode(Request $request, Server $node): void
    {
        $token = $request->user()?->currentAccessToken();
        if (!$token) {
            throw new AccessDeniedHttpException('Agent token required');
        }

        $allowed = self::allowedNodeIds($token);
        if ($allowed !== null && !in_array((int) $node->id, $allowed, true)) {
            throw new AccessDeniedHttpException('Agent token is not allowed to access this node');
        }
    }

    private static function idsWithPrefix(array $abilities, string $prefix): array
    {
        $ids = [];
        foreach ($abilities as $ability) {
            if (!str_starts_with($ability, $prefix)) {
                continue;
            }
            $id = (int) substr($ability, strlen($prefix));
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return self::normalizeIds($ids);
    }

    private static function normalizeIds(array $ids): array
    {
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, static fn (int $id) => $id > 0);
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
