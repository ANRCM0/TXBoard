<?php

return [
    // Disabled by default. Enable only after a successful provider sandbox
    // rehearsal and reverse-proxy/hostname callback reachability validation.
    'native_webhook_enabled' => (bool) env('TXBOARD_NATIVE_PAYMENT_WEBHOOK', false),
];
