<?php

return [
    // Enable only in a disposable, isolated database copy. There is no v2 writer switch.
    'isolated_copy' => (bool) env('CORE_SHADOW_ISOLATED_COPY', false),
    'subscription_identity_read' => (bool) env('CORE_SHADOW_SUBSCRIPTION_IDENTITY_READ', false),
];
