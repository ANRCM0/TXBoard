<?php

namespace App\Services;

/**
 * Keeps custom favicon metadata on the server-rendered Admin SPA entry.
 * This also covers the unauthenticated sign-in route, without exposing
 * administrator configuration endpoints or requiring a frontend API call.
 */
final class AdminFavicon
{
    public static function isValidUrl(mixed $value): bool
    {
        if (!is_string($value) || strlen($value) > 2048 || $value === '' ||
            filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $url = parse_url($value);
        return is_array($url)
            && strtolower((string) ($url['scheme'] ?? '')) === 'https'
            && !empty($url['host'])
            && !isset($url['user'])
            && !isset($url['pass']);
    }

    public static function inject(string $html, mixed $configuredUrl): string
    {
        if (!self::isValidUrl($configuredUrl)) {
            return $html;
        }
        $safeUrl = htmlspecialchars($configuredUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $icon = '<link id="txboard-admin-favicon" rel="icon" href="' . $safeUrl
            . '" referrerpolicy="no-referrer">' . "\n";
        return str_replace('</head>', '    ' . $icon . '</head>', $html);
    }
}
