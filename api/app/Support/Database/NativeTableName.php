<?php

namespace App\Support\Database;

/**
 * Deterministic table-name resolver for the future coordinated native cutover.
 * The default is always the existing v2 schema. No schema introspection or
 * implicit production switch is performed.
 */
final class NativeTableName
{
    public static function resolve(string $legacy, bool $native = false): string
    {
        if (!preg_match('/^v2_[a-z][a-z0-9_]*$/D', $legacy)) {
            throw new \InvalidArgumentException('Unsafe legacy table identifier');
        }
        return $native ? 'tx_' . substr($legacy, 3) : $legacy;
    }
}
