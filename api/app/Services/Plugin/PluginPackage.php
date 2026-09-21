<?php

namespace App\Services\Plugin;

use InvalidArgumentException;
use ZipArchive;

final class PluginPackage
{
    public const SCHEMA_VERSION = 1;
    private const MAX_ARCHIVE_ENTRIES = 2000;
    private const MAX_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;

    public function validateManifestExtension(array $config): bool
    {
        try {
            $this->assertManifestExtension($config);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function assertManifestExtension(array $config): void
    {
        $menus = $config['admin_menus'] ?? [];
        if (!is_array($menus)) {
            throw new InvalidArgumentException('admin_menus must be an array');
        }

        $appReferences = [];
        foreach ($menus as $menu) {
            if (!is_array($menu)) {
                throw new InvalidArgumentException('admin_menus entries must be objects');
            }
            if (array_key_exists('app', $menu)) {
                if (!is_string($menu['app']) || trim($menu['app']) === '') {
                    throw new InvalidArgumentException('admin menu app must be a non-empty string');
                }
                $appReferences[] = trim($menu['app']);
            }
        }

        $package = $config['package'] ?? null;
        if ($package === null && $appReferences === []) {
            return; // legacy package: keep backward compatibility
        }

        if (!is_array($package) || (int) ($package['schema'] ?? 0) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Plugin Package v1 requires package.schema=1');
        }

        foreach ($appReferences as $reference) {
            if (!$this->isSafeAdminAppReference($reference)) {
                throw new InvalidArgumentException("Invalid plugin admin app reference: {$reference}");
            }
        }
    }

    public function assertDeclaredAdminAppsExist(string $pluginPath, array $config): void
    {
        $this->assertManifestExtension($config);

        foreach (($config['admin_menus'] ?? []) as $menu) {
            if (!is_array($menu) || empty($menu['app'])) {
                continue;
            }

            $source = $this->resolveAdminEntrySource($pluginPath, (string) $menu['app']);
            if (!is_file($source)) {
                throw new InvalidArgumentException(
                    'Declared plugin admin app is missing: ' . $menu['app']
                );
            }
        }
    }

    public function isSafeAdminAppReference(string $reference): bool
    {
        $value = trim($reference);
        if ($value === '' || str_starts_with($value, '/') || str_contains($value, '\\')) {
            return false;
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value)) {
            return false;
        }

        $path = $this->referencePath($value);
        if (!str_starts_with($path, 'admin/') || !str_ends_with(strtolower($path), '.html')) {
            return false;
        }

        if (!preg_match('#^[A-Za-z0-9._/-]+$#', $path)) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    public function resolveAdminEntrySource(string $pluginPath, string $reference): string
    {
        if (!$this->isSafeAdminAppReference($reference)) {
            throw new InvalidArgumentException("Invalid plugin admin app reference: {$reference}");
        }

        $path = $this->referencePath($reference);
        $relative = substr($path, strlen('admin/'));

        return rtrim($pluginPath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'admin'
            . DIRECTORY_SEPARATOR . 'dist'
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function publicAssetBase(string $pluginCode): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $pluginCode)) {
            throw new InvalidArgumentException('Invalid plugin code');
        }

        return '/plugins/' . $pluginCode;
    }

    public function assertSafeArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw new InvalidArgumentException('插件包文件数量过多');
        }

        $uncompressed = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');
            $normalized = str_replace('\\', '/', $name);

            if (
                $normalized === ''
                || str_contains($normalized, "\0")
                || str_starts_with($normalized, '/')
                || preg_match('/^[A-Za-z]:\//', $normalized)
            ) {
                throw new InvalidArgumentException('插件包包含非法文件路径');
            }

            foreach (explode('/', rtrim($normalized, '/')) as $segment) {
                if ($segment === '..') {
                    throw new InvalidArgumentException('插件包包含目录穿越路径');
                }
            }

            $uncompressed += (int) ($stat['size'] ?? 0);
            if ($uncompressed > self::MAX_UNCOMPRESSED_BYTES) {
                throw new InvalidArgumentException('插件包解压后体积不能超过 50MB');
            }

            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                $mode = ($attributes >> 16) & 0170000;
                if ($mode === 0120000) {
                    throw new InvalidArgumentException('插件包不允许包含符号链接');
                }
            }
        }
    }

    private function referencePath(string $reference): string
    {
        $parts = preg_split('/[?#]/', $reference, 2);
        return (string) ($parts[0] ?? '');
    }
}
