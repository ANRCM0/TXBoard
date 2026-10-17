<?php

namespace App\Core\Kernel\Modules;

final class ModuleIdentifier
{
    public static function check(string $value): void
    {
        if (preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/D', $value) !== 1 || strlen($value) > 96) {
            throw new ModuleError('MODULE_IDENTIFIER_INVALID');
        }
    }
}
