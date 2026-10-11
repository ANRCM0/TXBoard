<?php

return [
    // The window bounds *request replay*, not the lifetime of a paid order.
    'ttl_hours' => (int) env('IDEMPOTENCY_TTL_HOURS', 24),
    'in_progress_timeout_seconds' => (int) env('IDEMPOTENCY_IN_PROGRESS_TIMEOUT', 900),
];
