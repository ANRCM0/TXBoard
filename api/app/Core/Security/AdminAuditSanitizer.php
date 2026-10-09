<?php

namespace App\Core\Security;

/**
 * Persistent administrative audit records outlive admin-path rotation and
 * secret policies. Sanitize both writes and historical reads.
 */
final class AdminAuditSanitizer
{
    public static function safeUri(string $uri): string
    {
        // Strip all query-string and fragment material, which may contain
        // API keys or reset/checkout tokens supplied by older controllers.
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') return '[REDACTED]';

        // The rotating admin path is not an action identifier. Never persist
        // it in audit history or return it to an operator after rotation.
        $path = preg_replace('~^/txapi/admin/[^/]+(?=/|$)~',
            '/txapi/admin/{admin_path}', $path);
        $path = preg_replace('~^/api/v2/[^/]+(?=/|$)~',
            '/api/v2/{admin_path}', $path);
        return $path;
    }

    public static function safeAction(string $action, string $uri): string
    {
        if (str_starts_with($action, 'txapi_admin_') ||
            str_starts_with($action, 'api_v2_')) {
            $path = self::safeUri($uri);
            $path = preg_replace('~^/(txapi/admin|api/v2)/\{admin_path\}/?~', '', $path);
            if (is_string($path) && $path !== '' && $path !== '[REDACTED]') {
                $parts = explode('/', trim($path, '/'));
                $verb = array_pop($parts);
                $domain = implode('_', $parts);
                return $domain === '' ? (string) $verb : $domain . '.' . $verb;
            }
            return '[REDACTED]';
        }
        return $action;
    }

    public static function safeJson(string $raw): string
    {
        if ($raw === '') return '';
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return '[REDACTED]';
        return json_encode(self::redact($decoded),
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[REDACTED]';
    }

    public static function redact(mixed $data, ?string $key = null): mixed
    {
        if ($key !== null) {
            $normalized = strtolower(str_replace(['-', '.', ' '], '_', $key));
            if ($normalized === 'key') return '[REDACTED]';
            foreach (['password','passwd','token','secret','api_key',
                'private_key','access_key','credential','authorization',
                'api_secret','signing_key'] as $fragment) {
                if (str_contains($normalized, $fragment)) return '[REDACTED]';
            }
        }
        if (!is_array($data)) return $data;
        $safe = [];
        foreach ($data as $itemKey => $value) {
            $safe[$itemKey] = self::redact($value,
                is_string($itemKey) ? $itemKey : null);
        }
        return $safe;
    }
}
