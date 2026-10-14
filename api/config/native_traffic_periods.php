<?php

return [
    // Native Node reports retain the observed reset epoch through durable replay.
    'enabled' => env('NATIVE_TRAFFIC_PERIODS_ENABLED', true),
];
