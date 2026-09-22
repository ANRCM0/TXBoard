<?php

namespace App\Services\Module\Adapters;

use App\Services\Module\ModuleAdapter;
use App\Services\Module\ModuleCapability;
use App\Services\Module\ModuleDescriptor;
use App\Services\Module\ModuleDiscoveryResult;
use App\Services\Module\ModuleHealth;
use App\Services\Module\ModuleManifest;
use App\Services\Module\ModuleSource;

final class AgentOpsModuleAdapter implements ModuleAdapter
{
    public function name(): string
    {
        return 'agent_ops';
    }

    public function discover(): ModuleDiscoveryResult
    {
        $version = (string) config('app.version', '1.0.0');

        $manifest = ModuleManifest::fromArray([
            'schema' => ModuleManifest::SCHEMA_VERSION,
            'module' => [
                'id' => 'agent_ops',
                'name' => 'Agent Ops',
                'version' => $version,
                'type' => 'agent',
                'description' => 'TXBoard AI-native operations and approval control plane',
                'author' => 'TXBoard',
            ],
            'compatibility' => [
                'txboard' => '*',
            ],
            'capabilities' => [
                ModuleCapability::AGENT_API,
                ModuleCapability::AGENT_ADMIN,
            ],
        ]);

        return new ModuleDiscoveryResult([
            new ModuleDescriptor(
                manifest: $manifest,
                source: ModuleSource::SYSTEM,
                installed: true,
                enabled: true,
                active: null,
                health: ModuleHealth::HEALTHY,
            ),
        ]);
    }
}
