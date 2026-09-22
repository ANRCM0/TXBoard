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
                } catch (Throwable $e) {
                    $errors[] = new ModuleDiscoveryError(
                        adapter: $this->name(),
                        moduleId: $code,
                        message: $e->getMessage(),
                    );
                    continue;
                }

                $row = $installed->get($manifest->id);
                $health = ModuleHealth::HEALTHY;

                try {
                    $this->pluginPackage->assertDeclaredAdminAppsExist($directory, $config);
                } catch (Throwable $e) {
                    $health = ModuleHealth::FAILED;
                    $errors[] = new ModuleDiscoveryError(
                        adapter: $this->name(),
                        moduleId: $manifest->id,
                        message: $e->getMessage(),
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
        } catch (Throwable $e) {
            $errors[] = new ModuleDiscoveryError(
                adapter: $this->name(),
                message: 'Plugin installation state is unavailable: ' . $e->getMessage(),
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
        if (is_array($menus) && $menus !== []) {
            $capabilities[] = ModuleCapability::ADMIN_MENU;
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

        return [
            'schema' => ModuleManifest::SCHEMA_VERSION,
            'module' => $module,
            'compatibility' => ['txboard' => trim($compatibility)],
            'capabilities' => array_values(array_unique($capabilities)),
        ];
    }
}
