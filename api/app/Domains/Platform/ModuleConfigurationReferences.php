<?php

namespace App\Domains\Platform;

use App\Extensions\Contracts\ExtensionFailure;
use App\Models\User;

/** Alias changes require trusted deployment policy for this installation, actor, module and field. */
final class ModuleConfigurationReferences
{
    public function replace(array $saved, array $changes, array $fields, User $actor, string $installationId, string $module): array
    {
        if (count($changes) > 16) throw new ExtensionFailure('MODULE_CONFIGURATION_INVALID', 422);
        $seen = [];
        foreach ($changes as $change) {
            $name = $change['name'] ?? null;
            $reference = $change['reference'] ?? null;
            if (!is_string($name) || !in_array($name, $fields, true) || isset($seen[$name]) || !is_string($reference)
                || preg_match('/\Asecret:([a-z][a-z0-9_]{0,63})\z/D', $reference, $match) !== 1) {
                throw new ExtensionFailure('MODULE_CONFIGURATION_INVALID', 422);
            }
            $seen[$name] = true;
            $allowed = false;
            foreach (config('core_modules.configuration_reference_grants', []) as $grant) {
                if (($grant['installation_id'] ?? null) === $installationId && ($grant['module'] ?? null) === $module
                    && ($grant['field'] ?? null) === $name && ($grant['actor_id'] ?? null) === (string) $actor->id
                    && ($grant['reference'] ?? null) === $reference) { $allowed = true; break; }
            }
            $value = config('core_modules.secrets.'.$match[1]);
            if (!$allowed || !is_string($value) || $value === '') throw new ExtensionFailure('MODULE_REFERENCE_DENIED', 403);
            $saved[$name] = $reference;
        }
        return $saved;
    }
}
