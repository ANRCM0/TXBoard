<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use App\Core\Security\AdminAuditSanitizer;
use Closure;

class RequestLog
{
    public function handle($request, Closure $next)
    {
        // Native admin writes use PUT/PATCH/DELETE as well as POST.
        // They must all produce the same redacted audit trail.
        if (!in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $response = $next($request);

        try {
            $admin = $request->user();
            if (!$admin || !$admin->is_admin) {
                return $response;
            }

            $action = $this->resolveAction($request->path());
            $data = AdminAuditSanitizer::redact($request->all());

            AdminAuditLog::insert([
                'admin_id' => $admin->id,
                'action' => $action,
                'method' => $request->method(),
                'uri' => AdminAuditSanitizer::safeUri($request->getRequestUri()),
                'request_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'ip' => $request->getClientIp(),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Audit log write failed: ' . $e->getMessage());
        }

        return $response;
    }

    private function resolveAction(string $path): string
    {
        // api/v2/{secure_path}/user/update → user.update
        $path = preg_replace('#^api/v[12]/[^/]+/#', '', $path);
        $path = preg_replace('#^txapi/admin/[^/]+/#', '', $path);
        // gift-card/create-template → gift_card.create_template
        $path = str_replace('-', '_', $path);
        // user/update → user.update, server/manage/sort → server_manage.sort
        $segments = explode('/', $path);
        $method = array_pop($segments);
        $resource = implode('_', $segments);

        return $resource . '.' . $method;
    }
}
