<?php

// This is a local/testing rehearsal, never a production activation switch.
return [
    'enabled' => env('CORE_INTERNAL_READONLY_ENABLED', false),
    'public_read_enabled' => env('CORE_INTERNAL_PUBLIC_READ_ENABLED', false),
    'login_enabled' => env('CORE_INTERNAL_LOGIN_ENABLED', false),
    'email_code_enabled' => env('CORE_INTERNAL_EMAIL_CODE_ENABLED', false),
    'register_enabled' => env('CORE_INTERNAL_REGISTER_ENABLED', false),
    'password_reset_enabled' => env('CORE_INTERNAL_PASSWORD_RESET_ENABLED', false),
    'sessions_enabled' => env('CORE_INTERNAL_SESSIONS_ENABLED', false),
    'admin_identity_enabled' => env('CORE_INTERNAL_ADMIN_IDENTITY_ENABLED', false),
    'admin_login_enabled' => env('CORE_INTERNAL_ADMIN_LOGIN_ENABLED', false),
    'admin_dashboard_enabled' => env('CORE_INTERNAL_ADMIN_DASHBOARD_ENABLED', false),
    'admin_order_list_enabled' => env('CORE_INTERNAL_ADMIN_ORDER_LIST_ENABLED', false),
    'admin_plan_list_enabled' => env('CORE_INTERNAL_ADMIN_PLAN_LIST_ENABLED', false),
    'admin_sessions_enabled' => env('CORE_INTERNAL_ADMIN_SESSIONS_ENABLED', false),
    'order_write_enabled' => env('CORE_INTERNAL_ORDER_WRITE_ENABLED', false),
    // Operator-controlled absolute file, not supplied by HTTP callers.
    'trust_file' => env('CORE_INTERNAL_READONLY_TRUST_FILE'),
    'issuer' => 'txboard-local-tls-boundary',
    'audience' => 'txboard-core-local',
    'max_body_bytes' => 16384,
    'max_proof_bytes' => 8192,
    'max_authorization_bytes' => 4096,
    'max_response_bytes' => 65536,
    'max_deadline_seconds' => 5,
    'max_proof_seconds' => 10,
    'clock_skew_seconds' => 2,
    'requests_per_minute' => 60,
    'audit_retention_seconds' => 86400,
];
