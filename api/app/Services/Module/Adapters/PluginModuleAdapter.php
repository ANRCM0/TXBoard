<?php

namespace App\Services\Module\Adapters;

use App\Models\Plugin;
use App\Services\Module\ModuleAdapter;
use App\Services\Module\ModuleCapability;
use App\Services\Module\ModuleDescriptor;
use App\Services\Module\ModuleDiscoveryError;
use App\Services\Module\ModuleDiscoveryResult;
use App\Services\Module\ModuleHealth;
use App\Services\Module\ModuleManifest;
use App\Services\Module\ModuleSource;
use App\Services\Plugin\PluginManager;
use App\Services\Plugin\PluginPackage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class PluginModuleAdapter implements ModuleAdapter
{
    public function __construct(
        private readonly PluginManager $pluginManager,
        private readonly PluginPackage $pluginPackage,
    ) {
    }

    public function name(): string
    {
        return 'plugin';
    }

    public function discover(): ModuleDiscoveryResult
    {
        $modules = [];
        $errors = [];
        $seen = [];
        $installed = $this->installedPlugins($errors);

        foreach ($this->pluginManager->getPluginPaths() as $index => $root) {
            if (!File::isDirectory($root)) {
                continue;
            }

            $source = $index === 0 ? ModuleSource::BUNDLED : ModuleSource::USER;

            foreach (File::directories($root) as $directory) {
                $configPath = $directory . '/config.json';
                if (!File::isFile($configPath)) {
                    continue;
                }

                $config = json_decode((string) File::get($configPath), true);
                if (!is_array($config)) {
                    $errors[] = new ModuleDiscoveryError(
                        adapter: $this->name(),
                        message: 'Plugin config.json is not valid JSON',
                    );
                    continue;
                }

                $code = is_string($config['code'] ?? null) ? trim($config['code']) : null;
                if ($code && isset($seen[$code])) {
                    continue;
                }
                if ($code) {
                    $seen[$code] = true;
                }

                try {
                    $manifest = ModuleManifest::fromArray($this->manifestFromLegacyConfig($directory, $config));
                } catch (Throwable) {
                    $errors[] = new ModuleDiscoveryError(
                        adapter: $this->name(),
                        moduleId: null,
                        message: 'Plugin metadata is invalid',
                    );
                    continue;
                }

                $row = $installed->get($manifest->id);
                $health = ModuleHealth::HEALTHY;

                try {
                    $this->pluginPackage->assertDeclaredAdminAppsExist($directory, $config);
                } catch (Throwable) {
                    $health = ModuleHealth::FAILED;
                    $errors[] = new ModuleDiscoveryError(
                        adapter: $this->name(),
                        moduleId: $manifest->id,
                        message: 'Plugin admin app validation failed',
                    );
                }

                $modules[] = new ModuleDescriptor(
                    manifest: $manifest,
                    source: $source,
                    installed: $row !== null,
                    enabled: (bool) ($row?->is_enabled ?? false),
                    active: null,
                    health: $health,
                );
            }
        }

        return new ModuleDiscoveryResult($modules, $errors);
    }

    /**
     * @param list<ModuleDiscoveryError> $errors
     * @return Collection<string, Plugin>
     */
    private function installedPlugins(array &$errors): Collection
    {
        try {
            if (!Schema::hasTable('v2_plugins')) {
                return collect();
            }

            return Plugin::query()->get()->keyBy('code');
        } catch (Throwable) {
            $errors[] = new ModuleDiscoveryError(
                adapter: $this->name(),
                message: 'Plugin installation state is unavailable',
            );

            return collect();
        }
    }

    private function manifestFromLegacyConfig(string $directory, array $config): array
    {
        foreach (['name', 'code', 'version', 'description', 'author'] as $field) {
            if (!is_string($config[$field] ?? null) || trim($config[$field]) === '') {
                throw new \InvalidArgumentException("Legacy plugin field {$field} is required");
            }
        }

        if (
            isset($config['type'])
            && !in_array($config['type'], [Plugin::TYPE_FEATURE, Plugin::TYPE_PAYMENT], true)
        ) {
            throw new \InvalidArgumentException('Legacy plugin type is not supported');
        }

        $code = trim($config['code']);
        $name = trim($config['name']);
        $version = trim($config['version']);
        $description = trim($config['description']);
        $author = trim($config['author']);

        $capabilities = [];

        $menus = $config['admin_menus'] ?? [];
        $navigation = $this->legacyAdminNavigation($menus);
        if ($navigation !== []) {
            $capabilities[] = ModuleCapability::ADMIN_MENU;
        }

        if (is_array($menus)) {
            foreach ($menus as $menu) {
                if (is_array($menu) && !empty($menu['app'])) {
                    $capabilities[] = ModuleCapability::ADMIN_APP;
                    break;
                }
            }
        }

        if (is_array($config['config'] ?? null) && $config['config'] !== []) {
            $capabilities[] = ModuleCapability::ADMIN_SETTINGS;
        }

        if (is_array($config['admin_crud'] ?? null) && $config['admin_crud'] !== []) {
            $capabilities[] = ModuleCapability::ADMIN_CRUD;
        }

        if (File::isFile($directory . '/routes/api.php')) {
            $capabilities[] = ModuleCapability::API_ROUTE;
        }

        if (File::isFile($directory . '/routes/web.php')) {
            $capabilities[] = ModuleCapability::WEB_ROUTE;
        }

        if (File::isDirectory($directory . '/database/migrations')) {
            $capabilities[] = ModuleCapability::DATABASE_MIGRATION;
        }

        if (File::isDirectory($directory . '/Commands')) {
            $capabilities[] = ModuleCapability::COMMAND;
        }

        if (($config['type'] ?? null) === Plugin::TYPE_PAYMENT) {
            $capabilities[] = ModuleCapability::PAYMENT_PROVIDER;
        }

        $requires = is_array($config['require'] ?? null) ? $config['require'] : [];
        $compatibility = $requires['txboard'] ?? $requires['xboard'] ?? '*';
        if (!is_string($compatibility) || trim($compatibility) === '') {
            $compatibility = '*';
        }

        $module = [
            'id' => $code,
            'name' => $name,
            'version' => $version,
            'type' => 'plugin',
        ];
        if ($description !== '') {
            $module['description'] = $description;
        }
        if ($author !== '') {
            $module['author'] = $author;
        }

        $manifest = [
            'schema' => ModuleManifest::SCHEMA_VERSION,
            'module' => $module,
            'compatibility' => ['txboard' => trim($compatibility)],
            'capabilities' => array_values(array_unique($capabilities)),
        ];

        if ($navigation !== []) {
            $manifest['admin'] = [
                'navigation' => $navigation,
            ];
        }

        return $manifest;
    }

    /**
     * @return list<array{id: string, title: string, path: string, icon?: string, order?: int}>
     */
    private function legacyAdminNavigation(mixed $menus): array
    {
        if (!is_array($menus) || !array_is_list($menus)) {
            return [];
        }

        $navigation = [];
        $seenIds = [];

        foreach ($menus as $index => $menu) {
            if (!is_array($menu) || array_is_list($menu)) {
                continue;
            }

            $path = $this->safeLegacyNavigationPath($menu['path'] ?? null);
            if ($path === null) {
                continue;
            }

            $title = null;
            foreach (['title', 'label'] as $field) {
                if (is_string($menu[$field] ?? null) && trim($menu[$field]) !== '') {
                    $candidate = trim($menu[$field]);
                    if (strlen($candidate) <= 120) {
                        $title = $candidate;
                        break;
                    }
                }
            }
            $title ??= $path;

            $id = $this->legacyNavigationId($menu['id'] ?? null, $path, $index);
            while (isset($seenIds[$id])) {
                $suffix = '-' . substr(hash('sha256', $path . ':' . $index . ':' . $id), 0, 8);
                $id = substr($id, 0, max(1, 64 - strlen($suffix))) . $suffix;
            }
            $seenIds[$id] = true;

            $item = [
                'id' => $id,
                'title' => $title,
                'path' => $path,
            ];

            if (
                is_string($menu['icon'] ?? null)
                && trim($menu['icon']) !== ''
                && strlen(trim($menu['icon'])) <= 80
            ) {
                $item['icon'] = trim($menu['icon']);
            }

            if (
                is_int($menu['order'] ?? null)
                && $menu['order'] >= -100000
                && $menu['order'] <= 100000
            ) {
                $item['order'] = $menu['order'];
            }

            $navigation[] = $item;
        }

        return $navigation;
    }

    private function safeLegacyNavigationPath(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $path = trim($value);
        if (
            $path === ''
            || str_contains($path, '\\')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path)
        ) {
            return null;
        }

        $path = trim($path, '/');
        if (
            $path === ''
            || !preg_match('/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/', $path)
        ) {
            return null;
        }

        return $path;
    }

    private function legacyNavigationId(mixed $value, string $path, int $index): string
    {
        if (
            is_string($value)
            && preg_match('/^[A-Za-z0-9_-]+$/', trim($value))
            && strlen(trim($value)) <= 64
        ) {
            return trim($value);
        }

        $candidate = str_replace('/', '-', $path);
        $candidate = preg_replace('/[^A-Za-z0-9_-]+/', '-', $candidate) ?? '';
        $candidate = trim($candidate, '-_');

        if ($candidate === '') {
            $candidate = 'nav-' . substr(hash('sha256', $path . ':' . $index), 0, 12);
        }

        return substr($candidate, 0, 64);
    }
}
