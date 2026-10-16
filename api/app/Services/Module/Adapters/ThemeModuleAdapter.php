<?php

namespace App\Services\Module\Adapters;

use App\Services\Module\ModuleAdapter;
use App\Services\Module\ModuleCapability;
use App\Services\Module\ModuleDescriptor;
use App\Services\Module\ModuleDiscoveryError;
use App\Services\Module\ModuleDiscoveryResult;
use App\Services\Module\ModuleHealth;
use App\Services\Module\ModuleId;
use App\Services\Module\ModuleManifest;
use App\Services\Module\ModuleSource;
use App\Services\ThemeService;
use Throwable;

final class ThemeModuleAdapter implements ModuleAdapter
{
    public function __construct(
        private readonly ThemeService $themes,
    ) {
    }

    public function name(): string
    {
        return 'theme';
    }

    public function discover(): ModuleDiscoveryResult
    {
        $modules = [];
        $errors = [];
        $active = $this->themes->getActiveTheme();

        foreach ($this->themes->getList() as $key => $config) {
            if (!is_array($config)) {
                continue;
            }

            $moduleId = ModuleId::theme((string) $key);
            $module = [
                'id' => $moduleId,
                'name' => (string) ($config['title'] ?? $config['name'] ?? $key),
                'version' => (string) ($config['version'] ?? ''),
                'type' => 'theme',
            ];
            foreach (['description', 'author'] as $field) {
                if (is_string($config[$field] ?? null) && $config[$field] !== '') {
                    $module[$field] = $config[$field];
                }
            }

            try {
                $manifest = ModuleManifest::fromArray([
                    'schema' => ModuleManifest::SCHEMA_VERSION,
                    'module' => $module,
                    'compatibility' => [
                        'txboard' => $config['compatibility']['txboard'] ?? '*',
                    ],
                    'capabilities' => [ModuleCapability::THEME],
                ]);
            } catch (Throwable) {
                $errors[] = new ModuleDiscoveryError(
                    adapter: $this->name(),
                    moduleId: $moduleId,
                    message: 'Theme module metadata is invalid',
                );
                continue;
            }

            $modules[] = new ModuleDescriptor(
                manifest: $manifest,
                source: !empty($config['is_system']) ? ModuleSource::SYSTEM : ModuleSource::USER,
                installed: true,
                enabled: true,
                active: ((string) $key) === $active,
                health: ModuleHealth::HEALTHY,
            );
        }

        return new ModuleDiscoveryResult($modules, $errors);
    }

}
