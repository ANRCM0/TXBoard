<?php

namespace App\Services\Plugin;

use App\Contracts\NativePaymentPluginInterface;
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
        $package = $config['package'] ?? null;
        if (!is_array($package) || ($package['schema'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Plugin Package v1 requires package.schema=1');
        }
        if (($config['type'] ?? null) === 'payment') {
            $payment = $config['payment'] ?? null;
            if (!is_array($payment) || array_is_list($payment)
                || count($payment) !== 3
                || ($payment['contract'] ?? null) !== NativePaymentPluginInterface::CONTRACT
                || !is_string($payment['provider'] ?? null)
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,31}\z/D', $payment['provider']) !== 1
                || !is_string($payment['snapshot_schema'] ?? null)
                || strlen($payment['snapshot_schema']) > 64
                || preg_match('~\A[a-z0-9][a-z0-9._-]*/[1-9][0-9]*\z~D', $payment['snapshot_schema']) !== 1) {
                throw new InvalidArgumentException('Payment plugins require the current explicit payment contract');
            }
        } elseif (array_key_exists('payment', $config)) {
            throw new InvalidArgumentException('Only payment plugins may declare the payment contract');
        }
        $menus = $config['admin_menus'] ?? [];
        if (!is_array($menus) || !array_is_list($menus)) {
            throw new InvalidArgumentException('admin_menus must be an array');
        }
        $ids = [];
        $paths = [];
        foreach ($menus as $menu) {
            if (!is_array($menu) || array_is_list($menu) ||
                array_diff(array_keys($menu), ['id', 'title', 'path', 'icon', 'order', 'description', 'app']) !== []) {
                throw new InvalidArgumentException('Invalid admin menu declaration');
            }
            foreach (['id' => '/\A[A-Za-z0-9_-]{1,64}\z/D',
                'path' => '/\A[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*\z/D'] as $key => $pattern) {
                if (!is_string($menu[$key] ?? null) || !preg_match($pattern, $menu[$key])) {
                    throw new InvalidArgumentException('Invalid admin menu '.$key);
                }
            }
            if (strlen($menu['path']) > 240 || isset($ids[$menu['id']]) || isset($paths[$menu['path']])) {
                throw new InvalidArgumentException('Admin menu identities and paths must be unique');
            }
            $ids[$menu['id']] = true;
            $paths[$menu['path']] = true;
            if (!is_string($menu['title'] ?? null) || trim($menu['title']) === '' || strlen($menu['title']) > 120) {
                throw new InvalidArgumentException('Admin menu title is required');
            }
            if (!is_string($menu['app'] ?? null) || !$this->isSafeAdminAppReference($menu['app'])) {
                throw new InvalidArgumentException('Invalid plugin admin app reference');
            }
            if (isset($menu['icon']) && (!is_string($menu['icon']) || trim($menu['icon']) === '' || strlen($menu['icon']) > 80)) {
                throw new InvalidArgumentException('Invalid admin menu icon');
            }
            if (isset($menu['order']) && (!is_int($menu['order']) || abs($menu['order']) > 100000)) {
                throw new InvalidArgumentException('Invalid admin menu order');
            }
        }
        if (isset($package['admin']) && (!is_array($package['admin']) ||
            ($package['admin']['format'] ?? null) !== 'static-app' ||
            ($package['admin']['dist'] ?? null) !== 'admin/dist' ||
            array_diff(array_keys($package['admin']), ['format', 'dist']) !== [])) {
            throw new InvalidArgumentException('Plugin admin package must use admin/dist static-app');
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
        $seenEntries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');
            if (str_contains($name, '\\')) {
                throw new InvalidArgumentException('Plugin archive contains Windows-style separators');
            }
            $normalized = $name;

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
                if ($segment === '' || $segment === '.') {
                    throw new InvalidArgumentException('Plugin archive contains unsafe path segments');
                }
            }
            $entryId = strtolower(rtrim($normalized, '/'));
            if (isset($seenEntries[$entryId])) {
                throw new InvalidArgumentException('Plugin archive contains duplicate paths');
            }
            $seenEntries[$entryId] = true;

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

    /**
     * Only one installable plugin root is allowed. This prevents an archive
     * from smuggling a second manifest which could be selected by glob order.
     */
    public function assertInstallableArchive(ZipArchive $zip): void
    {
        $candidates = [];
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (str_ends_with($entry, '/')) {
                continue;
            }
            $entries[$entry] = true;
            if (preg_match('#^(?:[A-Za-z0-9_-]+/)?config\\.json$#', $entry)) {
                $candidates[] = $entry;
            }
        }
        if (count($candidates) !== 1) {
            throw new InvalidArgumentException('Plugin archive must contain exactly one installable config.json');
        }
        $parent = dirname($candidates[0]);
        $runtimeFile = ($parent === '.' ? '' : $parent . '/') . 'Plugin.php';
        if (!isset($entries[$runtimeFile])) {
            throw new InvalidArgumentException('Plugin archive is missing Plugin.php in manifest root');
        }
    }

    private function referencePath(string $reference): string
    {
        $parts = preg_split('/[?#]/', $reference, 2);
        return (string) ($parts[0] ?? '');
    }
}
