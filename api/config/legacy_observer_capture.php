<?php

return [
    // Additional isolated opt-in; existing capture alone never changes Observer behavior.
    'enabled' => env('LEGACY_OBSERVER_CAPTURE_ENABLED', false),
];
