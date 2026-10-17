<?php

return [
    'enabled' => env('CORE_INTERNAL_API_ENABLED', false),
    // Exact socket peers, not X-Forwarded-For. The proxy MUST strip client
    // identity headers, validate mTLS (including revocation), and re-encrypt.
    'trusted_tls_proxies' => array_values(array_filter(array_map('trim',
        explode(',', (string) env('CORE_INTERNAL_TLS_PROXIES', ''))))),
    'max_request_bytes' => 32768,
    'max_response_bytes' => 1048576,
    'deadline_seconds' => 30,
];
