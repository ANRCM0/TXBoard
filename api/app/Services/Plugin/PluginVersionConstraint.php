<?php

namespace App\Services\Plugin;

use InvalidArgumentException;

/**
 * Small, strict semantic-version constraint evaluator.
 * Unknown grammar fails closed instead of silently accepting a dependency.
 */
final class PluginVersionConstraint
{
    public static function matches(string $installed, string $requirement): bool
    {
        $requirement = trim($requirement);
        if (!preg_match('/^\\d+\\.\\d+\\.\\d+(?:-[A-Za-z0-9.-]+)?$/', $installed)) {
            throw new InvalidArgumentException('Invalid installed plugin version');
        }
        if ($requirement === '*') {
            return true;
        }
        if (preg_match('/^(>=|<=|>|<|=|==)?\\s*(\\d+\\.\\d+\\.\\d+)$/', $requirement, $m)) {
            return version_compare($installed, $m[2], $m[1] ?: '>=' /* overridden below */)
                && ($m[1] !== '' || version_compare($installed, $m[2], '=='));
        }
        if (preg_match('/^\\^(\\d+)\\.(\\d+)\\.(\\d+)$/', $requirement, $m)) {
            $major = (int) $m[1]; $minor = (int) $m[2]; $patch = (int) $m[3];
            $upper = $major > 0 ? ($major + 1) . '.0.0'
                : ($minor > 0 ? '0.' . ($minor + 1) . '.0' : '0.0.' . ($patch + 1));
            return version_compare($installed, "{$major}.{$minor}.{$patch}", '>=')
                && version_compare($installed, $upper, '<');
        }
        if (preg_match('/^~(\\d+)\\.(\\d+)\\.(\\d+)$/', $requirement, $m)) {
            $lower = "{$m[1]}.{$m[2]}.{$m[3]}";
            $upper = $m[1] . '.' . ((int) $m[2] + 1) . '.0';
            return version_compare($installed, $lower, '>=')
                && version_compare($installed, $upper, '<');
        }
        throw new InvalidArgumentException('Unsupported plugin version constraint');
    }
}
