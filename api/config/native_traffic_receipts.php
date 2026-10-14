<?php

return [
    // Ordinary production native reception is durable by default. Explicit
    // legacy regression fixtures set false; recovery still drains prior rows.
    'enabled' => env('NATIVE_TRAFFIC_RECEIPTS_ENABLED', true),
];
