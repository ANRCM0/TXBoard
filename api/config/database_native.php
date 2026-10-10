<?php

return [
    // Must remain disabled until the coordinated schema cutover is verified.
    // NativeTableName::runtime also supports adapted raw SQL and modern migrations.
    // Third-party plugins and historical migration replay are NOT auto-translated.
    'native_tables' => env('TX_NATIVE_TABLES', false),
];
