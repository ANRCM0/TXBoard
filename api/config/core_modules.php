<?php

return [
    // Trusted deployment composition only; requests and package declarations cannot edit this list.
    'providers' => [],
    'release_id' => env('CORE_RELEASE_ID', env('APP_COMMIT', 'development')),
    'package_lock' => env('CORE_MODULE_PACKAGE_LOCK', base_path('bootstrap/module-packages.lock.json')),
    // Reference names are public configuration; values stay in the host's secret environment.
    'secrets' => (static function (): array {
        $file = env('CORE_MODULE_SECRETS_FILE');
        if ($file === null || $file === '') return [];
        if (!is_string($file) || !is_readable($file) || filesize($file) > 65536) throw new RuntimeException('MODULE_SECRET_MAP_INVALID');
        $values = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($values) || $values !== [] && array_is_list($values) || count($values) > 64) throw new RuntimeException('MODULE_SECRET_MAP_INVALID');
        foreach ($values as $name => $value) {
            if (!is_string($name) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $name) !== 1
                || !is_string($value) || $value === '' || strlen($value) > 16384) throw new RuntimeException('MODULE_SECRET_MAP_INVALID');
        }
        return $values;
    })(),
];
