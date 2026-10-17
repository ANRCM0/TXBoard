<?php

return [
    // Trusted deployment composition only; requests and package declarations cannot edit this list.
    'providers' => [],
    'release_id' => env('CORE_RELEASE_ID', env('APP_COMMIT', 'development')),
    'package_lock' => env('CORE_MODULE_PACKAGE_LOCK', base_path('bootstrap/module-packages.lock.json')),
    'outbound_hosts' => (static function (): array {
        $file = env('CORE_MODULE_OUTBOUND_POLICY_FILE');
        if ($file === null || $file === '') return [];
        $policies = \App\Core\Host\Configuration\TrustedConfiguration::object($file);
        if (count($policies) > 64) throw new RuntimeException('MODULE_OUTBOUND_POLICY_INVALID');
        foreach ($policies as $code => $hosts) {
            if (!is_string($code) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $code) !== 1
                || !is_array($hosts) || !array_is_list($hosts) || count($hosts) > 32) throw new RuntimeException('MODULE_OUTBOUND_POLICY_INVALID');
            foreach ($hosts as $host) {
                if (!is_string($host) || strlen($host) > 253 || preg_match('/\A[a-z0-9]+(?:[.-][a-z0-9]+)*\z/D', $host) !== 1) throw new RuntimeException('MODULE_OUTBOUND_POLICY_INVALID');
            }
        }
        return $policies;
    })(),
    // Reference names are public configuration; values stay in the host's secret environment.
    'secrets' => (static function (): array {
        $file = env('CORE_MODULE_SECRETS_FILE');
        if ($file === null || $file === '') return [];
        $values = \App\Core\Host\Configuration\TrustedConfiguration::object($file);
        if (!is_array($values) || $values !== [] && array_is_list($values) || count($values) > 64) throw new RuntimeException('MODULE_SECRET_MAP_INVALID');
        foreach ($values as $name => $value) {
            if (!is_string($name) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $name) !== 1
                || !is_string($value) || $value === '' || strlen($value) > 16384) throw new RuntimeException('MODULE_SECRET_MAP_INVALID');
        }
        return $values;
    })(),
];
