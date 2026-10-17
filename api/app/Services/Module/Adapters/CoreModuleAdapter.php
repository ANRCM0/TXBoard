<?php

namespace App\Services\Module\Adapters;

use App\Core\Host\Modules\ModuleRuntime;
use App\Core\Kernel\Modules\CapabilityDefinition;
use App\Services\Module\ModuleAdapter;
use App\Services\Module\ModuleDescriptor;
use App\Services\Module\ModuleDiscoveryError;
use App\Services\Module\ModuleDiscoveryResult;
use App\Services\Module\ModuleHealth;
use App\Services\Module\ModuleManifest;
use App\Services\Module\ModuleSource;

/** Projects the live composition into the existing read-only inventory. No second enable/install state. */
final class CoreModuleAdapter implements ModuleAdapter
{
    public function __construct(private readonly ModuleRuntime $runtime) {}

    public function name(): string { return 'core'; }

    public function discover(): ModuleDiscoveryResult
    {
        $modules = [];
        foreach ($this->runtime->plan()->modules as $definition) {
            if (str_starts_with($definition->id, 'plugin.')) continue;
            $manifest = ModuleManifest::fromArray([
                'schema' => 1,
                'module' => ['id' => 'core.'.$definition->id, 'name' => $definition->id,
                    'version' => $definition->version, 'type' => 'core'],
                'compatibility' => ['txboard' => '*'], 'capabilities' => [],
            ]);
            $services = array_map(static fn (CapabilityDefinition $capability): array => [
                'id' => $capability->id, 'version' => $capability->version,
            ], array_values(array_filter($definition->provides, static fn (CapabilityDefinition $capability): bool => $capability->exported)));
            $modules[] = new ModuleDescriptor($manifest, ModuleSource::SYSTEM, true, true, true,
                ModuleHealth::HEALTHY, services: $services);
        }
        $errors = [];
        foreach ($this->runtime->plan()->unavailable as $id => $code) {
            $errors[] = new ModuleDiscoveryError($this->name(), $code, 'core.'.$id);
        }
        return new ModuleDiscoveryResult($modules, $errors);
    }
}
