<?php

return [
    // First slice runs only against a disposable database. No production writer switch.
    'isolated_copy' => (bool) env('CORE_USAGE_V2_ISOLATED_COPY', false),
    'core_instance_id' => env('CORE_USAGE_V2_INSTANCE_ID'),
    'receipt_generation' => env('CORE_USAGE_V2_RECEIPT_GENERATION', '1'),
    'retention_seconds' => 604800,
    'max_late_report_seconds' => 86400,
    'receipt_retention_seconds' => 1209600,
    'max_future_seconds' => 30,
    'processing_lease_seconds' => 30,
];
