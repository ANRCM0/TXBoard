<?php

namespace Tests\Unit\Services\Module;

use App\Services\AgentOps\AgentOpsService;
use App\Services\Module\Adapters\AgentOpsModuleAdapter;
use App\Services\Module\ModuleHealth;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AgentOpsModuleAdapterTest extends TestCase
{
    public function test_healthy_runtime_status_produces_healthy_agent_module(): void
    {
        $ops = $this->createMock(AgentOpsService::class);
        $ops->expects($this->once())
            ->method('systemStatus')
            ->willReturn([
                'schedule' => true,
                'horizon' => true,
                'websocket_server' => true,
                'timestamp' => 1790112000,
            ]);

        $result = (new AgentOpsModuleAdapter($ops))->discover();
        $module = $result->modules[0];

        $this->assertSame(ModuleHealth::HEALTHY, $module->health);
        $this->assertSame('Agent Ops', $module->manifest->name);
        $this->assertSame('TXBoard AI-native operations and approval control plane', $module->manifest->description);
        $this->assertSame('TXBoard', $module->manifest->author);
        $this->assertSame(['agent.api', 'agent.admin'], $module->manifest->capabilities);
        $this->assertSame([
            'checks' => [
                'schedule' => true,
                'horizon' => true,
                'websocket_server' => true,
            ],
            'observed_at' => 1790112000,
        ], $module->healthDetails?->toArray());
    }

    public function test_failed_or_unknown_runtime_check_degrades_module_health(): void
    {
        $ops = $this->createMock(AgentOpsService::class);
        $ops->method('systemStatus')->willReturn([
            'schedule' => true,
            'horizon' => false,
            'timestamp' => 1790112001,
        ]);

        $module = (new AgentOpsModuleAdapter($ops))->discover()->modules[0];

        $this->assertSame(ModuleHealth::DEGRADED, $module->health);
        $this->assertSame([
            'schedule' => true,
            'horizon' => false,
            'websocket_server' => null,
        ], $module->healthDetails?->checks);
    }

    public function test_runtime_health_exception_keeps_agent_module_discoverable_without_leaking_error_text(): void
    {
        $ops = $this->createMock(AgentOpsService::class);
        $ops->method('systemStatus')
            ->willThrowException(new RuntimeException('redis password=super-secret'));

        $result = (new AgentOpsModuleAdapter($ops))->discover();

        $this->assertCount(1, $result->modules);
        $this->assertCount(1, $result->errors);
        $this->assertSame('agent_ops', $result->errors[0]->adapter);
        $this->assertSame('agent_ops', $result->errors[0]->moduleId);
        $this->assertSame('Agent Ops health status unavailable', $result->errors[0]->message);

        $payload = $result->modules[0]->toArray();

        $this->assertSame('agent_ops', $payload['id']);
        $this->assertSame('degraded', $payload['health']);
        $this->assertSame([
            'schedule' => null,
            'horizon' => null,
            'websocket_server' => null,
        ], $payload['health_details']['checks']);
        $serialized = json_encode([
            'module' => $payload,
            'errors' => array_map(
                static fn ($error) => $error->toArray(),
                $result->errors,
            ),
        ]);

        $this->assertStringNotContainsString('super-secret', (string) $serialized);
    }
}
