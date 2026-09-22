<?php

namespace Tests\Unit\Services\Module;

use App\Services\Module\Adapters\PluginLifecycleAdapter;
use App\Services\Module\ModuleDescriptor;
use App\Services\Module\ModuleHealth;
use App\Services\Module\ModuleLifecycleOperation;
use App\Services\Module\ModuleManifest;
use App\Services\Module\ModuleSource;
use App\Services\Module\ModuleType;
use App\Services\Plugin\PluginManager;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PluginLifecycleAdapterTest extends TestCase
{
    #[DataProvider('operationProvider')]
    public function test_each_operation_delegates_to_existing_plugin_manager(
        ModuleLifecycleOperation $operation,
        string $managerMethod,
    ): void {
        $manager = $this->createMock(PluginManager::class);
        $manager->expects($this->once())
            ->method($managerMethod)
            ->with('access_audit')
            ->willReturn(true);

        $adapter = new PluginLifecycleAdapter($manager);
        $adapter->execute($this->descriptor(ModuleType::PLUGIN), $operation);
    }

    public function test_adapter_only_supports_plugin_modules(): void
    {
        $adapter = new PluginLifecycleAdapter($this->createMock(PluginManager::class));

        $plugin = $this->descriptor(ModuleType::PLUGIN);
        $this->assertTrue($adapter->supports($plugin));
        $this->assertFalse($adapter->supports($this->descriptor(ModuleType::THEME)));
        $this->assertTrue(
            $adapter->supportsOperation($plugin, ModuleLifecycleOperation::UNINSTALL)
        );
        $this->assertTrue(
            $adapter->expectsModuleAfterOperation($plugin, ModuleLifecycleOperation::UNINSTALL)
        );
    }

    public function test_execute_rejects_non_plugin_module_when_called_directly(): void
    {
        $adapter = new PluginLifecycleAdapter($this->createMock(PluginManager::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('PluginLifecycleAdapter only supports plugin modules');

        $adapter->execute(
            $this->descriptor(ModuleType::THEME),
            ModuleLifecycleOperation::ENABLE,
        );
    }

    public static function operationProvider(): array
    {
        return [
            'install' => [ModuleLifecycleOperation::INSTALL, 'install'],
            'enable' => [ModuleLifecycleOperation::ENABLE, 'enable'],
            'disable' => [ModuleLifecycleOperation::DISABLE, 'disable'],
            'upgrade' => [ModuleLifecycleOperation::UPGRADE, 'update'],
            'uninstall' => [ModuleLifecycleOperation::UNINSTALL, 'uninstall'],
        ];
    }

    private function descriptor(ModuleType $type): ModuleDescriptor
    {
        return new ModuleDescriptor(
            manifest: ModuleManifest::fromArray([
                'schema' => 1,
                'module' => [
                    'id' => 'access_audit',
                    'name' => 'Access Audit',
                    'version' => '1.0.0',
                    'type' => $type->value,
                ],
                'compatibility' => ['txboard' => '*'],
                'capabilities' => [],
            ]),
            source: ModuleSource::USER,
            installed: true,
            enabled: false,
            active: null,
            health: ModuleHealth::HEALTHY,
        );
    }
}
