<?php

return [
    // These must be supplied from trusted deployed release metadata (not a ZIP).
    // Blank values fail closed during v2 installation and activation.
    'txboard_version' => env('THEME_V2_TXBOARD_VERSION', ''),
    'browser_version' => env('THEME_V2_BROWSER_VERSION', ''),
    'sdk_version' => env('THEME_V2_SDK_VERSION', ''),

    // Server-owned presentation-field allowlist. Manifest config.public can
    // only narrow this set; it can never grant public access to a new key.
    'public_fields' => ['theme_color', 'primaryColor', 'background_url', 'siteBanner'],
];
