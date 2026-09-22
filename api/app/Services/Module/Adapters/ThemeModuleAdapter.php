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
use App\Services\Theme\ThemePackageManifest;
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

            $moduleId = ModuleId::legacy('theme', (string) $key);
            $health = ModuleHealth::HEALTHY;
            $package = null;

            try {
                $package = ThemePackageManifest::fromArray($config);
            } catch (Throwable $e) {
                $health = ModuleHealth::DEGRADED;
                $errors[] = new ModuleDiscoveryError(
                    adapter: $this->name(),
                    moduleId: $moduleId,
                    message: 'Theme Package metadata is invalid: ' . $e->getMessage(),
                );
            }

            $displayName = $package?->name
                ?? trim((string) ($config['name'] ?? $key));
            $version = $package?->version
                ?? $this->legacyVersion($config['version'] ?? null);

            $module = [
                'id' => $moduleId,
                'name' => $displayName !== '' ? $displayName : (string) $key,
                'version' => $version,
                'type' => 'theme',
            ];

            $description = $package?->description
                ?? trim((string) ($config['description'] ?? ''));
            if ($description !== '') {
                $module['description'] = $description;
            }

            $author = $package?->author
                ?? trim((string) ($config['author'] ?? ''));
            if ($author !== '') {
                $module['author'] = $author;
            }

            try {
                $manifest = ModuleManifest::fromArray([
                    'schema' => ModuleManifest::SCHEMA_VERSION,
                    'module' => $module,
                    'compatibility' => [
                        'txboard' => $package?->txboardCompatibility ?? '*',
                    ],
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
                health: $health,
            );
        }

        return new ModuleDiscoveryResult($modules, $errors);
    }

    private function legacyVersion(mixed $version): string
    {
        $value = is_string($version) ? trim($version) : '';

        return preg_match(
            '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/',
            $value,
        ) ? $value : '0.0.0';
    }
}
