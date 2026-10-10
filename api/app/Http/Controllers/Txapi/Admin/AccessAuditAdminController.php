<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Domains\Network\NativeAccessAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AccessAuditAdminController
{
    public function rules(Request $request): JsonResponse
    {
        $rows = DB::table(NativeAccessAudit::rulesTable())->orderBy('id')->limit(500)
            ->get(['id', 'name', 'match_type', 'match_value', 'enabled', 'created_at', 'updated_at'])
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'match_type' => (string) $row->match_type,
                'match_value' => (string) $row->match_value,
                'enabled' => (bool) $row->enabled,
            ])->all();
        return TxapiResponse::success($request, $rows)->header('Cache-Control', 'no-store');
    }

    public function saveRule(Request $request): JsonResponse
    {
        $params = $request->validate([
            'id' => ['sometimes', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:120'],
            'match_type' => ['required', 'in:domain,domain_suffix,keyword,ip_cidr'],
            'match_value' => ['required', 'string', 'max:4000'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $name = trim($params['name']);
        $value = trim($params['match_value']);
        if ($name === '' || $value === '') {
            return TxapiResponse::error($request, 'AUDIT_RULE_INVALID', 'Rule name and value are required', 422);
        }
        $matchType = $params['match_type'];
        if ($matchType === 'ip_cidr') {
            foreach (preg_split('/[\r\n,]+/', $value) as $part) {
                $part = trim($part);
                if ($part === '' || !self::validNetwork($part)) {
                    return TxapiResponse::error($request, 'AUDIT_RULE_INVALID', 'Invalid IP/CIDR pattern', 422);
                }
            }
        }
        $table = DB::table(NativeAccessAudit::rulesTable());
        $data = [
            'name' => $name,
            'match_type' => $matchType,
            'match_value' => $value,
            'enabled' => $params['enabled'] ?? true,
            'updated_at' => now(),
        ];
        if (isset($params['id'])) {
            $id = (int) $params['id'];
            if (!$table->where('id', $id)->exists()) {
                return TxapiResponse::error($request, 'AUDIT_RULE_NOT_FOUND', 'Rule not found', 404);
            }
            $table->where('id', $id)->update($data);
        } else {
            if ($table->count() >= 500) {
                return TxapiResponse::error($request, 'AUDIT_RULE_LIMIT', 'At most 500 audit rules', 422);
            }
            $id = (int) $table->insertGetId($data + ['created_at' => now()]);
        }
        return TxapiResponse::success($request, ['id' => $id, 'ok' => true])->header('Cache-Control', 'no-store');
    }

    private static function validNetwork(string $value): bool
    {
        $parts = explode('/', $value, 2);
        if (filter_var($parts[0], FILTER_VALIDATE_IP) === false) return false;
        if (count($parts) === 1) return true;
        if (!ctype_digit($parts[1])) return false;
        $max = filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32 : 128;
        return (int) $parts[1] <= $max;
    }

    public function deleteRule(Request $request): JsonResponse
    {
        $id = (int) $request->route('id');
        $removed = DB::table(NativeAccessAudit::rulesTable())->where('id', $id)->delete();
        if (!$removed) return TxapiResponse::error($request, 'AUDIT_RULE_NOT_FOUND', 'Rule not found', 404);
        return TxapiResponse::success($request, ['ok' => true]);
    }

    public function events(Request $request): JsonResponse
    {
        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'server_id' => ['sometimes', 'integer', 'min:1'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'matched' => ['sometimes', 'boolean'],
            'keyword' => ['sometimes', 'string', 'max:100'],
        ]);
        $q = DB::table(NativeAccessAudit::eventsTable());
        foreach (['server_id', 'user_id', 'matched'] as $field) {
            if (array_key_exists($field, $params)) $q->where($field, $params[$field]);
        }
        if (!empty($params['keyword'])) {
            $q->where('target', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $params['keyword']).'%');
        }
        $page = $q->orderByDesc('id')->paginate((int) ($params['per_page'] ?? 30),
            ['id', 'server_id', 'user_id', 'target', 'target_ip', 'source_ip', 'matched', 'created_at'],
            'page', (int) ($params['page'] ?? 1));
        $rows = collect($page->items())->map(static fn ($e): array => [
            'id' => (int) $e->id,
            'server_id' => (int) $e->server_id,
            'user_id' => (int) $e->user_id,
            'target' => (string) $e->target,
            'target_ip' => $e->target_ip,
            'source_ip' => $e->source_ip,
            'matched' => (bool) $e->matched,
            'created_at' => (int) $e->created_at,
        ])->all();
        return TxapiResponse::success($request, $rows, [
            'page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage(),
        ])->header('Cache-Control', 'no-store');
    }
}
