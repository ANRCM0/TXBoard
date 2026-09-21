<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;

class RequestLog
{
    private const REDACTED = '[REDACTED]';

    /**
     * Fragments that identify credentials even when they are nested or use a
     * domain-specific prefix (email_password, server_token, client_secret...).
     */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'passwd',
        'token',
        'secret',
        'api_key',
        'private_key',
        'access_key',
        'credential',
        'authorization',
    ];

    public function handle($request, Closure $next)
    {
        if ($request->method() !== 'POST') {
            return $next($request);
        }

        $response = $next($request);

        try {
            $admin = $request->user();
            if (!$admin || !$admin->is_admin) {
                return $response;
            }

            $action = $this->resolveAction($request->path());
            $data = $this->redactSensitiveData($request->all());

            AdminAuditLog::insert([
                'admin_id' => $admin->id,
                'action' => $action,
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
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

    /**
     * Recursively redact credentials before an administrator request is
     * persisted. Plugin/payment configs commonly nest secrets under "config",
     * so top-level Collection::except() is not sufficient.
     */
    protected function redactSensitiveData(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return self::REDACTED;
        }

        if (!is_array($value)) {
            return $value;
        }

        $sanitized = [];
        foreach ($value as $childKey => $childValue) {
            $sanitized[$childKey] = $this->redactSensitiveData(
                $childValue,
                is_string($childKey) ? $childKey : null
            );
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '.', ' '], '_', $key));

        // Preserve the old exact "key" behavior without treating unrelated
        // words such as "keyboard_layout" as secrets.
        if ($normalized === 'key') {
            return true;
        }

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function resolveAction(string $path): string
    {
        // api/v2/{secure_path}/user/update → user.update
        $path = preg_replace('#^api/v[12]/[^/]+/#', '', $path);
        // gift-card/create-template → gift_card.create_template
        $path = str_replace('-', '_', $path);
        // user/update → user.update, server/manage/sort → server_manage.sort
        $segments = explode('/', $path);
        $method = array_pop($segments);
        $resource = implode('_', $segments);

        return $resource . '.' . $method;
    }
}
