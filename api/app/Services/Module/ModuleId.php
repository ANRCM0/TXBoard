<?php

namespace App\Services\Module;

final class ModuleId
{
    public static function theme(string $value): string
    {
        $prefix = 'theme';
        $raw = trim($value);
        $lower = strtolower($raw);
        $normalized = preg_replace('/[^a-z0-9._-]+/', '-', $lower) ?? '';
        $normalized = trim($normalized, '.-_');

        if ($normalized === '') {
            $normalized = 'name-' . substr(hash('sha256', $raw), 0, 12);
        } elseif ($normalized !== $lower) {
            $normalized .= '-' . substr(hash('sha256', $raw), 0, 8);
        }

        $maxSuffix = 64 - strlen($prefix) - 1;
        if (strlen($normalized) > $maxSuffix) {
            $hash = substr(hash('sha256', $raw), 0, 8);
            $normalized = substr($normalized, 0, max(1, $maxSuffix - 9)) . '-' . $hash;
        }

        return $prefix . '.' . $normalized;
    }
}
