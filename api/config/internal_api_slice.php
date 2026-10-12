<?php

// This is a local/testing rehearsal, never a production activation switch.
return [
    'enabled' => env('CORE_INTERNAL_READONLY_ENABLED', false),
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
