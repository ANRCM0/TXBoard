<?php

namespace App\Http\Controllers\Txapi\Admin;

use App\Core\Http\TxapiResponse;
use App\Core\Security\AdminAuditSanitizer;
use App\Models\AdminAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuditLogController
{
    /**
     * Native Admin only. Do not serialize Eloquent audit records wholesale:
     * legacy payloads may predate the request-time secret redaction policy.
     */
    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'action' => ['sometimes', 'nullable', 'string', 'max:80'],
            'admin_id' => ['sometimes', 'integer', 'min:1'],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $query = AdminAuditLog::query()->with('admin:id,email');
        if (!empty($params['action'])) {
            // Historical action names can embed a rotated administrator path.
            // Filtering them would disclose it through pagination counts.
            if (!preg_match('/\A[A-Za-z0-9_.-]+\z/D', $params['action'])
                || AdminAuditSanitizer::safeAction($params['action'], '') === '[REDACTED]') {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('action', $params['action']);
            }
        }
        if (!empty($params['admin_id'])) {
            $query->where('admin_id', (int) $params['admin_id']);
        }
        if (!empty($params['keyword'])) {
            $term = $params['keyword'];
            $query->where(function ($q) use ($term) {
                // Search only public metadata; redacting the returned payload
                // cannot protect secrets used in a WHERE/count predicate.
                $q->where('method', 'like', '%' . $term . '%')
                    ->orWhere('ip', 'like', '%' . $term . '%')
                    ->orWhereHas('admin', static fn ($admin) => $admin->where('email', 'like', '%' . $term . '%'));
            });
        }

        $page = $query->orderByDesc('id')
            ->paginate((int) ($params['per_page'] ?? 20),
                ['id', 'admin_id', 'action', 'method', 'uri', 'request_data', 'ip', 'created_at'],
                'page', (int) ($params['page'] ?? 1));
        $records = $page->getCollection()->map(static function (AdminAuditLog $row): array {
            return [
                'id' => (int) $row->id,
                'admin_id' => (int) $row->admin_id,
                'action' => AdminAuditSanitizer::safeAction((string) ($row->action ?? ''), (string) ($row->uri ?? '')),
                'method' => (string) ($row->method ?? ''),
                'uri' => AdminAuditSanitizer::safeUri((string) ($row->uri ?? '')),
                'ip' => (string) ($row->ip ?? ''),
                'created_at' => (int) $row->created_at,
                'admin' => $row->admin ? [
                    'id' => (int) $row->admin->id,
                    'email' => (string) $row->admin->email,
                ] : null,
                'request_data' => AdminAuditSanitizer::safeJson((string) ($row->request_data ?? '')),
            ];
        })->all();

        return TxapiResponse::success($request, $records, [
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ]);
    }

}
