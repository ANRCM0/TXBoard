<?php

return [
    // Laravel's env helper normally normalizes booleans; the explicit allow-list
    // also handles a string value from an uncached/custom bootstrap safely.
    'enabled' => in_array(env('NATIVE_NODE_AUTHORIZATION_ENABLED', false), [true, 1, '1', 'true', 'TRUE', 'on', 'ON', 'yes', 'YES'], true),
    'core_instance_id' => env('NATIVE_NODE_AUTHORIZATION_CORE_INSTANCE_ID'),
    'key_id' => env('NATIVE_NODE_AUTHORIZATION_KEY_ID'),
    'key_epoch' => env('NATIVE_NODE_AUTHORIZATION_KEY_EPOCH'),
    // Base64url encoded 32-byte Ed25519 seed. Never reuse APP_KEY or node tokens.
    'private_seed' => env('NATIVE_NODE_AUTHORIZATION_PRIVATE_SEED'),
    'policy_version' => 'native-node-authorization/v1',
    'lease_seconds' => env('NATIVE_NODE_AUTHORIZATION_LEASE_SECONDS', 120),
    'max_lease_seconds' => 120,
    'refresh_interval_seconds' => 30,
    'max_clock_skew_seconds' => env('NATIVE_NODE_AUTHORIZATION_MAX_CLOCK_SKEW_SECONDS', 5),
];
