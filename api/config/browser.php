<?php

return [
    'enabled' => env('CORE_BROWSER_ENABLED', true),
    'user_enabled' => env('CORE_BROWSER_USER_ENABLED', true),
    'admin_enabled' => env('CORE_BROWSER_ADMIN_ENABLED', true),
    'commerce_enabled' => env('CORE_BROWSER_COMMERCE_ENABLED', true),
    'max_request_bytes' => 32768,
    'max_response_bytes' => 1048576,
    'deadline_seconds' => 30,
    'ingress_per_minute' => 600,
    'rate_store' => env('CORE_BROWSER_RATE_STORE', 'redis'),
    // Explicit origin is independent of Host and untrusted forwarded headers.
    'origin' => env('CORE_BROWSER_ORIGIN', env('APP_URL', 'http://localhost')),
    'trusted_proxies' => array_values(array_filter(array_map('trim',
        explode(',', (string) env('CORE_BROWSER_TRUSTED_PROXIES', '127.0.0.1,::1'))))),
];
