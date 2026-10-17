<?php

namespace App\Extensions\SDK;

/** Operator-owned destination policy for trusted in-process connectors; this is not a network sandbox. */
final class OutboundTargets
{
    public static function https(string $module, mixed $url): bool
    {
        $parts = is_string($url) && strlen($url) <= 2048 ? parse_url($url) : false;
        $hosts = config('core_modules.outbound_hosts.'.$module, []);
        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])
            && is_array($hosts) && in_array($parts['host'] ?? null, $hosts, true);
    }
}
