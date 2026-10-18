<?php

namespace App\Core\Host\Modules;

use App\Extensions\Contracts\ExtensionFailure;

final class ModuleSecrets
{
    public function resolve(array $configuration, array $fields): array
    {
        foreach ($fields as $field) {
            $reference = $configuration[$field] ?? null;
            if (!is_string($reference) || preg_match('/\Asecret:([a-z][a-z0-9_]{0,63})\z/D', $reference, $match) !== 1) {
                throw new ExtensionFailure('MODULE_SECRET_REFERENCE_INVALID');
            }
            $value = config('core_modules.secrets.'.$match[1]);
            if (!is_string($value) || $value === '' || strlen($value) > 16384) throw new ExtensionFailure('MODULE_SECRET_UNAVAILABLE', 503);
            $configuration[$field] = $value;
        }
        return $configuration;
    }
}
