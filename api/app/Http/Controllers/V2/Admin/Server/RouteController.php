<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RouteController extends Controller
{
    public function fetch(Request $request)
    {
        $routes = ServerRoute::query()
            ->orderByRaw('CASE WHEN sort IS NULL THEN 1 ELSE 0 END')
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(function (ServerRoute $route) {
                $route->setAttribute('server_count', $this->serverUsageQuery($route->id)->count());
                return $route;
            });

        return $this->success($routes);
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer',
            'remarks' => 'required|string|max:255',
            'match' => 'required|array|min:1',
            'match.*' => 'required|string|max:255',
            'action' => 'required|in:block,direct,dns,proxy',
            'action_value' => 'nullable|string|max:255|required_if:action,dns,proxy',
            'enabled' => 'nullable|boolean',
            'sort' => 'nullable|integer|min:0',
        ], [
            'remarks.required' => '备注不能为空',
            'match.required' => '匹配值不能为空',
            'match.min' => '至少需要一条匹配规则',
            'action.required' => '动作类型不能为空',
            'action.in' => '动作类型参数有误',
            'action_value.required_if' => 'DNS / 代理动作必须填写目标出站标签',
        ]);

        $params['remarks'] = trim($params['remarks']);
        $params['match'] = array_values(array_unique(array_filter(array_map(
            fn ($item) => trim((string) $item),
            $params['match']
        ))));

        if (empty($params['match'])) {
            return $this->fail([422, '至少需要一条有效匹配规则']);
        }

        if (in_array($params['action'], ['block', 'direct'], true)) {
            $params['action_value'] = null;
        }

        if (!array_key_exists('enabled', $params)) {
            $params['enabled'] = true;
        }

        try {
            if (!empty($params['id'])) {
                $route = ServerRoute::find($params['id']);
                if (!$route) {
                    return $this->fail([400202, '路由不存在']);
                }
                $route->update($params);
                return $this->success(true);
            }

            if (!array_key_exists('sort', $params) || $params['sort'] === null) {
                $params['sort'] = ((int) ServerRoute::max('sort')) + 10;
            }

            ServerRoute::create($params);
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, !empty($params['id']) ? '保存失败' : '创建失败']);
        }
    }

    public function sort(Request $request)
    {
        $items = $request->validate([
            '*.id' => 'required|integer',
            '*.sort' => 'required|integer|min:0',
        ]);

        try {
            DB::transaction(function () use ($items) {
                foreach ($items as $item) {
                    $route = ServerRoute::find($item['id']);
                    if (!$route) {
                        continue;
                    }
                    $route->sort = $item['sort'];
                    $route->save();
                }
            });
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '排序保存失败']);
        }

        return $this->success(true);
    }

    public function simulate(Request $request)
    {
        $params = $request->validate([
            'node_id' => 'required|integer|exists:v2_server,id',
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
            return $this->success($result);
        }

        if (empty($routeIds)) {
            return $this->success($result);
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

        return $this->success($result);
    }

    public function drop(Request $request)
    {
        $route = ServerRoute::find($request->input('id'));
        if (!$route) {
            throw new ApiException('路由不存在');
        }

        if ($this->serverUsageQuery($route->id)->exists()) {
            return $this->fail([400, '该路由仍被节点使用，请先从节点配置中解除关联']);
        }

        if (!$route->delete()) {
            throw new ApiException('删除失败');
        }

        return $this->success(true);
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
