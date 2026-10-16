<?php

return [
    'provider_id' => env('NATIVE_PROVIDER_NODE_PROVIDER_ID'),
    // Operator-provisioned map: node ID => ['machine_id' => integer|null, 'recipient_sha256' => lowercase DER SHA256].
    // A missing or mismatched Node/Machine binding refuses authorization.
    'recipients' => [],
];
