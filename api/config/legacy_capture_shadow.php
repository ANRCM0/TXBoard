<?php

return [
    // Strict booleans are validated by the service; strings such as "1" never enable capture.
    'enabled' => env('LEGACY_CAPTURE_SHADOW_ENABLED', false),
    'run_key' => env('LEGACY_CAPTURE_SHADOW_RUN'),
    'billing_timezone' => env('LEGACY_CAPTURE_SHADOW_TIMEZONE'),
    'currency' => env('LEGACY_CAPTURE_SHADOW_CURRENCY'),
];
