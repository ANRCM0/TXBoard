<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Models\Server;
use App\Models\ServerRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NetworkRouteAdminController
{
    public function index(Request $request): JsonResponse
    {
        $counts = [];
        Server::query()->select(['id', 'route_ids'])->orderBy('id')
            ->chunkById(250, function ($nodes) use (&$counts): void {
                foreach ($nodes as $node) {
                    foreach (array_unique(array_map('intval', $node->route_ids ?? [])) as $id) {
                        if ($id > 0) $counts[$id] = ($counts[$id] ?? 0) + 1;
                    }
                }
            });

        $rows = ServerRoute::query()
            ->orderByRaw('CASE WHEN sort IS NULL THEN 1 ELSE 0 END')
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'remarks', 'match', 'action', 'action_value', 'enabled',
                'sort', 'created_at', 'updated_at'])
            ->map(static fn (ServerRoute $route): array => [
                'id' => $route->id, 'remarks' => $route->remarks,
                'match' => $route->match, 'action' => $route->action,
                'action_value' => $route->action_value, 'enabled' => $route->enabled,
                'sort' => $route->sort, 'server_count' => $counts[$route->id] ?? 0,
                'created_at' => $route->created_at, 'updated_at' => $route->updated_at,
            ])->all();

        return TxapiResponse::success($request, $rows)->header('Cache-Control', 'no-store');
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['sometimes', 'integer', 'min:1'],
            'remarks' => ['required', 'string', 'max:255'],
            'match' => ['required', 'array', 'min:1', 'max:200'],
            'match.*' => ['required', 'string', 'max:255'],
            'action' => ['required', 'in:block,direct,dns,proxy'],
            'action_value' => ['nullable', 'string', 'max:255', 'required_if:action,dns,proxy'],
            'enabled' => ['sometimes', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0'],
        ]);
        $data['remarks'] = trim($data['remarks']);
        $data['match'] = array_values(array_unique(array_filter(
            array_map(static fn ($entry): string => trim($entry), $data['match']),
            static fn (string $entry): bool => $entry !== ''
        )));
        if ($data['remarks'] === '' || $data['match'] === []) {
            return TxapiResponse::error($request, 'NETWORK_ROUTE_INVALID',
                'A nonempty name and match pattern are required', 422);
        }
        if (in_array($data['action'], ['block', 'direct'], true)) {
            $data['action_value'] = null;
        }
        if (!array_key_exists('enabled', $data)) $data['enabled'] = true;

        if (isset($data['id'])) {
            $route = ServerRoute::query()->find($data['id']);
            if (!$route) return TxapiResponse::error($request, 'NETWORK_ROUTE_NOT_FOUND', 'Route not found', 404);
            $route->update($data);
        } else {
            $data['sort'] = $data['sort'] ?? ((int) ServerRoute::query()->max('sort') + 10);
            $route = ServerRoute::query()->create($data);
        }
        return TxapiResponse::success($request, ['ok' => true, 'id' => (int) $route->id]);
    }

    public function sort(Request $request): JsonResponse
    {
        $items = $request->validate([
            '*.id' => ['required', 'integer', 'min:1'],
            '*.sort' => ['required', 'integer', 'min:0'],
        ]);
        if (count($items) < 1 || count($items) > 500) {
            return TxapiResponse::error($request, 'NETWORK_ROUTE_SORT_INVALID',
                'Route sort requires 1 to 500 entries', 422);
        }
        $ids = array_column($items, 'id');
        if (count(array_unique($ids)) !== count($ids)) {
            return TxapiResponse::error($request, 'NETWORK_ROUTE_SORT_DUPLICATE',
                'Duplicate route IDs are not allowed', 422);
        }
        $result = DB::transaction(function () use ($items, $ids): bool {
            $routes = ServerRoute::query()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            if ($routes->count() !== count($ids)) return false;
            foreach ($items as $item) {
                $route = $routes->get($item['id']);
                $route->sort = $item['sort'];
                $route->save();
            }
            return true;
        });
        if (!$result) return TxapiResponse::error($request, 'NETWORK_ROUTE_NOT_FOUND', 'Route not found', 404);
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function simulate(Request $request)
    {
        $params = $request->validate([
            'node_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists(\App\Support\Database\NativeTableName::runtime('v2_server'), 'id')],
            'target' => 'required|string|max:512',
        ]);

        $node = Server::findOrFail($params['node_id']);
        $target = $this->normalizeTarget($params['target']);
        $routeIds = array_values(array_filter(array_map('intval', $node->route_ids ?? [])));

        $result = [
            'node' => [
                'id' => $node->id,
                'name' => $node->name,
            ],
            'target' => $target,
            'authoritative' => empty($node->custom_routes),
            'warning' => empty($node->custom_routes)
                ? null
                : '该节点存在 custom_routes，它们优先于 panel 路由；以下结果仅表示 panel 路由层。',
            'match' => null,
            'evaluated_routes' => [],
            'unresolved_patterns' => [],
        ];

        if ($this->matchesBuiltinPrivateBlock($target)) {
            $result['match'] = [
                'layer' => 'built_in',
                'remarks' => '内核私网 / 保留地址保护',
                'action' => 'block',
                'action_value' => null,
                'pattern' => $target,
            ];
            return TxapiResponse::success($request, $result);
        }

        if (empty($routeIds)) {
            return TxapiResponse::success($request, $result);
        }

        $routes = ServerRoute::query()
            ->whereIn('id', $routeIds)
            ->where('enabled', true)
            ->orderByRaw('CASE WHEN sort IS NULL THEN 1 ELSE 0 END')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        foreach ($routes as $route) {
            [$matched, $pattern, $unresolved] = $this->evaluateRoute($route, $target);
            $result['evaluated_routes'][] = [
                'id' => $route->id,
                'remarks' => $route->remarks,
                'matched' => $matched,
                'matched_pattern' => $pattern,
                'action' => $route->action,
                'action_value' => $route->action_value,
            ];
            $result['unresolved_patterns'] = array_values(array_unique(array_merge(
                $result['unresolved_patterns'],
                $unresolved
            )));

            if ($matched) {
                $result['match'] = [
                    'layer' => 'panel',
                    'id' => $route->id,
                    'remarks' => $route->remarks,
                    'action' => $route->action,
                    'action_value' => $route->action_value,
                    'pattern' => $pattern,
                ];
                break;
            }
        }

        return TxapiResponse::success($request, $result);
    }


    public function delete(Request $request): JsonResponse
    {
        // The dynamic {admin_path} is the first route parameter; read id by
        // name rather than relying on parameter position.
        $id = (int) $request->route('id');
        $status = DB::transaction(function () use ($id): string {
            $route = ServerRoute::query()->lockForUpdate()->find($id);
            if (!$route) return 'missing';
            if ($this->serverUsageQuery($id)->exists()) return 'in_use';
            $route->delete();
            return 'ok';
        });
        if ($status === 'missing') return TxapiResponse::error($request, 'NETWORK_ROUTE_NOT_FOUND', 'Route not found', 404);
        if ($status === 'in_use') return TxapiResponse::error($request, 'NETWORK_ROUTE_IN_USE', 'Route is assigned to nodes', 409);
        return TxapiResponse::success($request, ['ok' => true]);
    }

    private function serverUsageQuery(int $routeId)
    {
        return Server::query()->where(function ($query) use ($routeId) {
            $query->whereJsonContains('route_ids', $routeId)
                ->orWhereJsonContains('route_ids', (string) $routeId);
        });
    }

    private function normalizeTarget(string $input): string
    {
        $target = trim($input);

        if (str_starts_with($target, '[') && str_ends_with($target, ']')) {
            $target = trim($target, '[]');
        }

        if (filter_var($target, FILTER_VALIDATE_IP)) {
            return $target;
        }

        $host = null;
        if (str_contains($target, '://')) {
            $host = parse_url($target, PHP_URL_HOST);
        } else {
            $host = parse_url('http://' . $target, PHP_URL_HOST);
        }

        return strtolower(rtrim((string) ($host ?: $target), '.'));
    }

    /**
     * @return array{0:bool,1:?string,2:array<int,string>}
     */
    private function evaluateRoute(ServerRoute $route, string $target): array
    {
        $unresolved = [];
        $isIp = filter_var($target, FILTER_VALIDATE_IP) !== false;

        foreach ($route->match ?? [] as $rawPattern) {
            $pattern = trim((string) $rawPattern);
            if ($pattern === '') {
                continue;
            }

            if (str_starts_with($pattern, 'geoip:') || str_starts_with($pattern, 'geosite:')) {
                $unresolved[] = $pattern;
                continue;
            }

            if (str_contains($pattern, '/')) {
                if ($isIp && $this->cidrContains($target, $pattern)) {
                    return [true, $pattern, $unresolved];
                }
                continue;
            }

            if ($isIp) {
                continue;
            }

            $domain = strtolower(ltrim($pattern, '*.'));
            if ($target === $domain || str_ends_with($target, '.' . $domain)) {
                return [true, $pattern, $unresolved];
            }
        }

        return [false, null, $unresolved];
    }

    private function matchesBuiltinPrivateBlock(string $target): bool
    {
        if (!filter_var($target, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach ([
            '10.0.0.0/8',
            '100.64.0.0/10',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '172.16.0.0/12',
            '192.0.0.0/24',
            '192.168.0.0/16',
            '198.18.0.0/15',
            'fc00::/7',
            'fe80::/10',
            '::1/128',
        ] as $cidr) {
            if ($this->cidrContains($target, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function cidrContains(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }

        [$network, $prefixRaw] = explode('/', $cidr, 2);
        $ipBytes = @inet_pton($ip);
        $networkBytes = @inet_pton($network);
        if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes)) {
            return false;
        }

        $maxBits = strlen($ipBytes) * 8;
        $prefix = filter_var($prefixRaw, FILTER_VALIDATE_INT);
        if ($prefix === false || $prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($ipBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
    }
}
