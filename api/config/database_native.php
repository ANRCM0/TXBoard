<?php

return [
    // Must remain disabled until the coordinated schema cutover is verified.
    // This setting affects opted-in Eloquent models only, not raw SQL or plugins.
    'native_tables' => env('TX_NATIVE_TABLES', false),
];
