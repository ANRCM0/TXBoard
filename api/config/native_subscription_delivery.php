<?php

return [
    // Fixed public Provider render endpoints; never selected by a request.
    // provider_id => ['endpoint' => 'https://provider.example/subscription-render']
    'providers' => env('CORE_CREDENTIAL_PROVIDER_ID') && env('NATIVE_SUBSCRIPTION_RENDER_ENDPOINT')
        ? [(string) env('CORE_CREDENTIAL_PROVIDER_ID') => ['endpoint' => env('NATIVE_SUBSCRIPTION_RENDER_ENDPOINT')]] : [],
];
