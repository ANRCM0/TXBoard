<?php

namespace App\Services\Module\Adapters;

use App\Services\AgentOps\AgentOpsService;
use App\Services\Module\ModuleAdapter;
use App\Services\Module\ModuleCapability;
use App\Services\Module\ModuleDescriptor;
use App\Services\Module\ModuleDiscoveryError;
use App\Services\Module\ModuleDiscoveryResult;
use App\Services\Module\ModuleHealth;
use App\Services\Module\ModuleHealthDetails;
use App\Services\Module\ModuleManifest;
use App\Services\Module\ModuleSource;
use Throwable;

final class AgentOpsModuleAdapter implements ModuleAdapter
{
    public function __construct(
        private readonly AgentOpsService $agentOps,
    ) {
    }

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

        [$health, $healthDetails, $healthUnavailable] = $this->runtimeHealth();

        return new ModuleDiscoveryResult(
            modules: [
                new ModuleDescriptor(
                    manifest: $manifest,
                    source: ModuleSource::SYSTEM,
                    installed: true,
                    enabled: true,
                    active: null,
                    health: $health,
                    healthDetails: $healthDetails,
                ),
            ],
            errors: $healthUnavailable
                ? [
                    new ModuleDiscoveryError(
                        adapter: $this->name(),
                        moduleId: 'agent_ops',
                        message: 'Agent Ops health status unavailable',
                    ),
                ]
                : [],
        );
    }

    /**
     * @return array{ModuleHealth, ModuleHealthDetails, bool}
     */
    private function runtimeHealth(): array
    {
        try {
            $status = $this->agentOps->systemStatus();

            $checks = [
                'schedule' => $this->booleanCheck($status, 'schedule'),
                'horizon' => $this->booleanCheck($status, 'horizon'),
                'websocket_server' => $this->booleanCheck($status, 'websocket_server'),
            ];

            $healthy = !in_array(false, $checks, true)
                && !in_array(null, $checks, true);

            $observedAt = (int) ($status['timestamp'] ?? 0);
            if ($observedAt <= 0) {
                $observedAt = time();
            }

            return [
                $healthy ? ModuleHealth::HEALTHY : ModuleHealth::DEGRADED,
                ModuleHealthDetails::fromChecks($checks, $observedAt),
                false,
            ];
        } catch (Throwable) {
            return [
                ModuleHealth::DEGRADED,
                ModuleHealthDetails::fromChecks([
                    'schedule' => null,
                    'horizon' => null,
                    'websocket_server' => null,
                ]),
                true,
            ];
        }
    }

    private function booleanCheck(array $status, string $key): ?bool
    {
        if (!array_key_exists($key, $status) || !is_bool($status[$key])) {
            return null;
        }

        return $status[$key];
    }
}
