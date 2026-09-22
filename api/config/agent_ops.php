<?php

return [
    'action_timeout' => max(30, (int) env('AGENT_OPS_ACTION_TIMEOUT', 120)),
    'network_allowlist' => array_values(array_filter(array_map(
        static fn ($value) => strtolower(trim($value)),
        explode(',', (string) env('AGENT_OPS_NETWORK_ALLOWLIST', ''))
    ))),
];
