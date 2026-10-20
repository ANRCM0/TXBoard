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
    // Browser alias replacement is narrower than trusted host/CLI configuration.
    'configuration_reference_grants' => (static function (): array {
        $file = env('CORE_MODULE_REFERENCE_POLICY_FILE');
        if ($file === null || $file === '') return [];
        $policy = \App\Core\Host\Configuration\TrustedConfiguration::object($file);
        $grants = $policy['grants'] ?? null;
        if (array_keys($policy) !== ['grants'] || !is_array($grants) || !array_is_list($grants) || count($grants) > 256) {
            throw new RuntimeException('MODULE_REFERENCE_POLICY_INVALID');
        }
        foreach ($grants as $grant) {
            if (!is_array($grant) || array_diff(array_keys($grant), ['installation_id', 'module', 'field', 'actor_id', 'reference'])
                || count($grant) !== 5 || !is_string($grant['installation_id'] ?? null) || strlen($grant['installation_id']) > 80
                || !is_string($grant['actor_id'] ?? null) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $grant['actor_id']) !== 1
                || !is_string($grant['module'] ?? null) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $grant['module']) !== 1
                || !is_string($grant['field'] ?? null) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $grant['field']) !== 1
                || !is_string($grant['reference'] ?? null) || preg_match('/\Asecret:[a-z][a-z0-9_]{0,63}\z/D', $grant['reference']) !== 1) {
                throw new RuntimeException('MODULE_REFERENCE_POLICY_INVALID');
            }
        }
        return $grants;
    })(),
];
