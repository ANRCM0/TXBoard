<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Allowed origins
    |--------------------------------------------------------------------------
    |
    | Both SPAs are normally served by the same gateway as the API, so they are
    | same-origin and need no CORS entry at all -- which is why the default is
    | an empty list rather than "*". Set CORS_ALLOWED_ORIGINS to a comma
    | separated list only when a frontend really lives on another origin (a CDN
    | or a separate admin domain, for example).
    |
    | "*" is deliberately not the default: it lets any website read this API's
    | responses from a visitor's browser.
    |
    */

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ), fn (string $origin): bool => $origin !== '')),

    // Regex patterns, for cases like "https://*.example.com". Kept empty by
    // default for the same reason as above.
    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS_PATTERNS', ''))
    ), fn (string $pattern): bool => $pattern !== '')),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
