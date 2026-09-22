<?php

return [
    'action_timeout' => max(30, (int) env('AGENT_OPS_ACTION_TIMEOUT', 120)),
    'action_cooldown' => max(0, (int) env('AGENT_OPS_ACTION_COOLDOWN', 30)),
    'max_pending_per_token' => max(1, (int) env('AGENT_OPS_MAX_PENDING_PER_TOKEN', 20)),
    'max_pending_per_node' => max(1, (int) env('AGENT_OPS_MAX_PENDING_PER_NODE', 5)),
    'log_max_lines' => max(1, min(500, (int) env('AGENT_OPS_LOG_MAX_LINES', 200))),
    'log_max_bytes' => max(1024, min(262144, (int) env('AGENT_OPS_LOG_MAX_BYTES', 65536))),
    'network_allowlist' => array_values(array_filter(array_map(
        static fn ($value) => strtolower(trim($value)),
        explode(',', (string) env('AGENT_OPS_NETWORK_ALLOWLIST', ''))
    ))),
];
