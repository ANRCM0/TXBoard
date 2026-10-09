<?php

return [
    // Workerman listens on the existing ws-server port (default 8076).
    // Production reverse proxy must route Upgrade requests for this path.
    // Off until that public route and TLS are configured and tested.
    'native_enabled' => (bool) env('TXBOARD_NATIVE_NODE_WS_ENABLED', false),
    'native_path' => '/txapi/node/v1/ws',
];
