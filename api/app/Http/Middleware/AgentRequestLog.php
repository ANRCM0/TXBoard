<?php

namespace App\Http\Middleware;

use App\Models\AgentAuditLog;
use Closure;
use Illuminate\Support\Str;

class AgentRequestLog
{
    private const REDACTED = '[REDACTED]';

    private const SENSITIVE = [
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
        $requestId = (string) ($request->header('X-Request-ID') ?: Str::ulid());
        $request->attributes->set('agent_request_id', $requestId);

        $response = null;
        $error = null;

        try {
            $response = $next($request);
            return $response;
        } catch (\Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            try {
                $user = $request->user();
                $token = $user?->currentAccessToken();

                if ($user && $token) {
                    $nodeId = $request->route('nodeId');
                    $actionRequestId = $request->route('requestId');
                    $status = $error
                        ? 'failed'
                        : (($response && $response->getStatusCode() < 400) ? 'succeeded' : 'failed');

                    AgentAuditLog::create([
                        'request_id' => $requestId,
                        'admin_id' => $user->id,
                        'token_id' => $token->id ?? null,
                        'client_name' => preg_replace('/^agent:/', '', (string) $token->name),
                        'tool' => $this->toolName($request),
                        'risk_level' => $request->isMethod('GET') ? 'read' : 'operate',
                        'target_type' => $nodeId ? 'node' : ($actionRequestId ? 'action' : null),
                        'target_id' => $nodeId ? (string) $nodeId : ($actionRequestId ? (string) $actionRequestId : null),
                        'input_redacted' => json_encode($this->redact($request->all()), JSON_UNESCAPED_UNICODE),
                        'result_status' => $status,
                        'result_summary' => $error
                            ? mb_substr($error->getMessage(), 0, 1000)
                            : 'HTTP ' . ($response?->getStatusCode() ?? 500),
                        'error_code' => $error ? class_basename($error) : null,
                        'ip' => $request->getClientIp(),
                    ]);
                }
            } catch (\Throwable $auditError) {
                \Log::warning('Agent audit log write failed: ' . $auditError->getMessage());
            }

            if ($response) {
                $response->headers->set('X-TXBoard-Request-ID', $requestId);
            }
        }
    }

    private function toolName($request): string
    {
        $path = preg_replace('#^api/v2/agent/?#', '', $request->path());
        return 'txboard_' . str_replace(['/', '-', '{', '}'], ['_', '_', '', ''], trim((string) $path, '/'));
    }

    private function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->sensitiveKey($key)) {
            return self::REDACTED;
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $childKey => $childValue) {
            $out[$childKey] = $this->redact($childValue, is_string($childKey) ? $childKey : null);
        }
        return $out;
    }

    private function sensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '.', ' '], '_', $key));
        if ($normalized === 'key') {
            return true;
        }
        foreach (self::SENSITIVE as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }
        return false;
    }
}
