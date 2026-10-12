<?php

return [
    'enabled' => (bool) env('CREDENTIAL_PROVIDER_FIXTURE_ENABLED', false),
    'plugin_bridge_enabled' => env('CREDENTIAL_PROVIDER_FIXTURE_PLUGIN_BRIDGE_ENABLED', false) === true,
    // Operator-controlled local fixture directory. Never supplied by a request or persisted in Core SQL.
    'private_root' => env('CREDENTIAL_PROVIDER_FIXTURE_PRIVATE_ROOT'),
    'wall_timeout_seconds' => 1.0,
    // Testing-only fault injection, never part of an issue/query DTO.
    'test_fault' => null,
];
