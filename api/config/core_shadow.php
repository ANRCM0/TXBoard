<?php

return [
    // Remaining historical writers require an explicitly disposable database.
    'isolated_copy' => (bool) env('CORE_SHADOW_ISOLATED_COPY', false),
];
