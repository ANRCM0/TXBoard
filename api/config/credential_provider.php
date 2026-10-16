<?php

$provider = (string) env('CORE_CREDENTIAL_PROVIDER_ID', '');

return [
    // Operator-owned entries; endpoint is the complete HTTPS /operations URL.
    'providers' => $provider === '' ? [] : [
        $provider => [
            'endpoint' => env('CORE_CREDENTIAL_PROVIDER_ENDPOINT'),
            'instance_epoch' => (string) env('CORE_CREDENTIAL_PROVIDER_EPOCH', '1'),
            'ca_file' => env('CORE_CREDENTIAL_PROVIDER_CA_FILE'),
            'client_cert' => env('CORE_CREDENTIAL_PROVIDER_CERT_FILE'),
            'client_key' => env('CORE_CREDENTIAL_PROVIDER_KEY_FILE'),
            'profiles' => explode(',', (string) env('CORE_CREDENTIAL_PROVIDER_PROFILES', 'vless-uuid/v1,vmess-uuid/v1')),
        ],
    ],
];
