<?php

namespace App\Core\Host\Modules;

use App\Models\Plugin;

/** A current package/state observation; never a grant to mutate a later state. */
final class ModuleDeploymentRevision
{
    public static function of(array $package, ?Plugin $row): string
    {
        return hash_hmac('sha256', json_encode([
            $package['code'], $package['fingerprint'], $row !== null, $row?->version,
            $row === null ? null : (bool) $row->is_enabled, $row?->module_schema_version,
            $row?->module_config_version, $row?->config, $row?->native_payment_mutation_token,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
