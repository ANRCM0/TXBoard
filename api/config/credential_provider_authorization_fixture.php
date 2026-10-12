<?php

return ['enabled' => env('CREDENTIAL_PROVIDER_AUTHORIZATION_FIXTURE_ENABLED', false) === true,
    'trust_file' => env('CREDENTIAL_PROVIDER_AUTHORIZATION_FIXTURE_TRUST_FILE')];
