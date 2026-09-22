<?php

return [
    'action_timeout' => max(30, (int) env('AGENT_OPS_ACTION_TIMEOUT', 120)),
    'action_cooldown' => max(0, (int) env('AGENT_OPS_ACTION_COOLDOWN', 30)),
    'max_pending_per_token' => max(1, (int) env('AGENT_OPS_MAX_PENDING_PER_TOKEN', 20)),
    'max_pending_per_node' => max(1, (int) env('AGENT_OPS_MAX_PENDING_PER_NODE', 5)),
    'log_max_lines' => max(1, min(200, (int) env('AGENT_OPS_LOG_MAX_LINES', 200))),
    'log_max_bytes' => max(1024, min(65536, (int) env('AGENT_OPS_LOG_MAX_BYTES', 65536))),
    'inspection_enabled' => filter_var(env('AGENT_OPS_INSPECTION_ENABLED', true), FILTER_VALIDATE_BOOL),
    'inspection_retention_days' => max(1, min(90, (int) env('AGENT_OPS_INSPECTION_RETENTION_DAYS', 7))),
    'pairing_cache_store' => env('AGENT_OPS_PAIRING_CACHE_STORE', 'redis'),
    'pairing_ttl_seconds' => max(60, min(900, (int) env('AGENT_OPS_PAIRING_TTL_SECONDS', 600))),
    'network_allowlist' => array_values(array_filter(array_map(
        static fn ($value) => strtolower(trim($value)),
        explode(',', (string) env('AGENT_OPS_NETWORK_ALLOWLIST', ''))
    ))),
];
