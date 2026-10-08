<?php

namespace App\Services\Theme;

use InvalidArgumentException;
use JsonException;
use ZipArchive;

final class ThemePackage
{
    private const CONFIG_FILE = 'config.json';
    private const ENTRY_FILE = 'dashboard.blade.php';
    private const MAX_ARCHIVE_ENTRIES = 2000;
    private const MAX_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;

    public function assertSafeArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw new InvalidArgumentException('Theme package contains too many files');
        }

        $uncompressed = 0;
        $seenEntries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');
            if (str_contains($name, '\\')) {
                throw new InvalidArgumentException('Theme package contains an invalid file path');
            }
            $normalized = $name;

            if (
                $normalized === ''
                || str_contains($normalized, chr(0))
                || str_starts_with($normalized, '/')
                || preg_match('/^[A-Za-z]:\//', $normalized)
            ) {
                throw new InvalidArgumentException('Theme package contains an invalid file path');
            }

            foreach (explode('/', rtrim($normalized, '/')) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    throw new InvalidArgumentException('Theme archive contains unsafe path segments');
                }
            }
            $entryId = strtolower(rtrim($normalized, '/'));
            if (isset($seenEntries[$entryId])) {
                throw new InvalidArgumentException('Theme archive contains duplicate paths');
            }
            $seenEntries[$entryId] = true;

            $uncompressed += (int) ($stat['size'] ?? 0);
            if ($uncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                throw new InvalidArgumentException('Theme package uncompressed size exceeds 50MB');
            }

            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                $mode = ($attributes >> 16) & 0170000;
                if ($mode === 0120000) {
                    throw new InvalidArgumentException('Theme package must not contain symbolic links');
                }
            }
        }
    }

    public function configEntry(ZipArchive $zip): string
    {
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (!str_ends_with($name, '/') && basename($name) === self::CONFIG_FILE) {
                $entries[] = $name;
            }
        }

        if (count($entries) !== 1) {
            throw new InvalidArgumentException('Theme package must contain exactly one config.json');
        }

        $entry = $entries[0];
        if (count(explode('/', $entry)) > 2) {
            throw new InvalidArgumentException(
                'Theme config.json must be at archive root or one top-level theme directory'
            );
        }

        return $entry;
    }

    public function sourcePath(string $extractPath, string $configEntry): string
    {
        $relative = dirname($configEntry);
        if ($relative === '.') {
            return rtrim($extractPath, DIRECTORY_SEPARATOR);
        }

        return rtrim($extractPath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function manifestFromFile(string $configFile): ThemePackageManifest
    {
        if (!is_file($configFile)) {
            throw new InvalidArgumentException('Theme config file not found');
        }

        try {
            $config = json_decode(
                (string) file_get_contents($configFile),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw new InvalidArgumentException('Theme config.json is not valid JSON');
        }

        if (!is_array($config) || array_is_list($config)) {
            throw new InvalidArgumentException('Theme config.json must be an object');
        }

        return ThemePackageManifest::fromArray($config);
    }

    public function assertRequiredFiles(string $sourcePath): void
    {
        $entry = rtrim($sourcePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . self::ENTRY_FILE;

        if (!is_file($entry)) {
            throw new InvalidArgumentException('Missing required theme file: dashboard.blade.php');
        }
    }
}
