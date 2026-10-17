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
use App\Core\Host\Modules\ModulePackageLock;
use App\Core\Host\Modules\ModuleRuntime;
use App\Services\Module\ModuleHealthDetails;

final class PluginModuleAdapter implements ModuleAdapter
{
    public function __construct(
        private readonly PluginManager $pluginManager,
        private readonly PluginPackage $pluginPackage,
        private readonly ModulePackageLock $packages,
        private readonly ModuleRuntime $runtime,
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
                    $manifest = ModuleManifest::fromArray($this->manifestFromPackage($directory, $config));
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

                $details = null;
                $services = [];
                $active = null;
                if (File::isFile($directory.'/module.json')) {
                    $package = $this->packages->package($manifest->id);
                    $composed = $package !== null && in_array('plugin.'.$manifest->id, array_column($this->runtime->plan()->modules, 'id'), true);
                    $schema = $package !== null && $row !== null && (int) $row->module_schema_version === $package['manifest']['schema_version'];
                    $version = $package !== null && $row !== null && $row->version === $package['version'];
                    $fence = $row !== null && $row->native_payment_mutation_token === null;
                    $active = $composed && $schema && $version && $fence && $row->is_enabled && $health === ModuleHealth::HEALTHY;
                    $details = ModuleHealthDetails::fromChecks(['composition' => $composed, 'schema' => $schema, 'package_version' => $version, 'mutation_idle' => $fence]);
                    $health = !$composed ? ModuleHealth::INCOMPATIBLE : ($row === null || !$row->is_enabled ? ModuleHealth::DISABLED
                        : ($active ? ModuleHealth::HEALTHY : ModuleHealth::DEGRADED));
                    foreach ($package['definition']->provides ?? [] as $capability) {
                        if ($capability->exported) $services[] = ['id' => $capability->id, 'version' => $capability->version];
                    }
                }
                $modules[] = new ModuleDescriptor(
                    manifest: $manifest,
                    source: $source,
                    installed: $row !== null,
                    enabled: (bool) ($row?->is_enabled ?? false),
                    active: $active,
                    health: $health,
                    healthDetails: $details,
                    services: $services,
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
            if (!Schema::hasTable('tx_plugins')) {
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

    private function manifestFromPackage(string $directory, array $config): array
    {
        $this->pluginPackage->assertManifestExtension($config);
        foreach (['name', 'code', 'version', 'description', 'author'] as $field) {
            if (!is_string($config[$field] ?? null) || trim($config[$field]) === '') {
                throw new \InvalidArgumentException("Plugin field {$field} is required");
            }
        }

        if (
            isset($config['type'])
            && !in_array($config['type'], [Plugin::TYPE_FEATURE, Plugin::TYPE_PAYMENT], true)
        ) {
            throw new \InvalidArgumentException('Plugin type is not supported');
        }

        $code = trim($config['code']);
        $name = trim($config['name']);
        $version = trim($config['version']);
        $description = trim($config['description']);
        $author = trim($config['author']);

        $capabilities = [];

        $menus = $config['admin_menus'] ?? [];
        $navigation = array_map(static fn (array $menu): array => array_intersect_key($menu,
            array_flip(['id', 'title', 'path', 'icon', 'order'])), $menus);
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
        $compatibility = $requires['txboard'] ?? '*';
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

}
