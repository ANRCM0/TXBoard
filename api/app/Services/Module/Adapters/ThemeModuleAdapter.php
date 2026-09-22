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

            $displayName = trim((string) ($config['name'] ?? $key));
            $moduleId = ModuleId::legacy('theme', (string) $key);

            try {
                $module = [
                    'id' => $moduleId,
                    'name' => $displayName !== '' ? $displayName : (string) $key,
                    'version' => trim((string) ($config['version'] ?? '0.0.0')),
                    'type' => 'theme',
                ];

                $description = trim((string) ($config['description'] ?? ''));
                if ($description !== '') {
                    $module['description'] = $description;
                }

                $author = trim((string) ($config['author'] ?? ''));
                if ($author !== '') {
                    $module['author'] = $author;
                }

                $manifest = ModuleManifest::fromArray([
                    'schema' => ModuleManifest::SCHEMA_VERSION,
                    'module' => $module,
                    'compatibility' => ['txboard' => '*'],
                    'capabilities' => [ModuleCapability::THEME],
                ]);
            } catch (Throwable $e) {
                $errors[] = new ModuleDiscoveryError(
                    adapter: $this->name(),
                    moduleId: $moduleId,
                    message: $e->getMessage(),
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
