<?php

namespace Tests\Unit\Services\Module;

use App\Services\Module\Adapters\ThemeLifecycleAdapter;
use App\Services\Module\ModuleDescriptor;
use App\Services\Module\ModuleHealth;
use App\Services\Module\ModuleId;
use App\Services\Module\ModuleLifecycleOperation;
use App\Services\Module\ModuleManifest;
use App\Services\Module\ModuleSource;
use App\Services\Module\ModuleType;
use App\Services\ThemeService;
use LogicException;
use PHPUnit\Framework\TestCase;

class ThemeLifecycleAdapterTest extends TestCase
{
    public function test_enable_delegates_to_theme_switch_using_runtime_theme_name(): void
    {
        $themes = $this->createMock(ThemeService::class);
        $themes->method('getList')->willReturn([
            'CustomTheme' => ['name' => 'CustomTheme'],
        ]);
        $themes->expects($this->once())
            ->method('switch')
            ->with('CustomTheme')
            ->willReturn(true);

        $adapter = new ThemeLifecycleAdapter($themes);
        $adapter->execute(
            $this->descriptor('CustomTheme', ModuleSource::USER, false),
            ModuleLifecycleOperation::ENABLE,
        );
    }

    public function test_uninstall_delegates_to_delete_for_inactive_user_theme(): void
    {
        $themes = $this->createMock(ThemeService::class);
        $themes->method('getList')->willReturn([
            'CustomTheme' => ['name' => 'CustomTheme'],
        ]);
        $themes->expects($this->once())
            ->method('delete')
            ->with('CustomTheme')
            ->willReturn(true);

        $adapter = new ThemeLifecycleAdapter($themes);
        $module = $this->descriptor('CustomTheme', ModuleSource::USER, false);

        $this->assertTrue(
            $adapter->supportsOperation($module, ModuleLifecycleOperation::UNINSTALL)
        );
        $this->assertFalse(
            $adapter->expectsModuleAfterOperation($module, ModuleLifecycleOperation::UNINSTALL)
        );

        $adapter->execute($module, ModuleLifecycleOperation::UNINSTALL);
    }

    public function test_system_active_and_disable_operations_are_not_exposed(): void
    {
        $adapter = new ThemeLifecycleAdapter($this->createMock(ThemeService::class));

        $system = $this->descriptor('TXBoard', ModuleSource::SYSTEM, true);
        $activeUser = $this->descriptor('CustomTheme', ModuleSource::USER, true);
        $inactiveUser = $this->descriptor('CustomTheme', ModuleSource::USER, false);

        $this->assertFalse(
            $adapter->supportsOperation($system, ModuleLifecycleOperation::ENABLE)
        );
        $this->assertFalse(
            $adapter->supportsOperation($system, ModuleLifecycleOperation::UNINSTALL)
        );
        $this->assertFalse(
            $adapter->supportsOperation($activeUser, ModuleLifecycleOperation::UNINSTALL)
        );
        $this->assertFalse(
            $adapter->supportsOperation($inactiveUser, ModuleLifecycleOperation::DISABLE)
        );
        $this->assertTrue(
            $adapter->supportsOperation($inactiveUser, ModuleLifecycleOperation::ENABLE)
        );
    }

    public function test_direct_unsupported_execution_is_rejected_before_runtime_call(): void
    {
        $adapter = new ThemeLifecycleAdapter($this->createMock(ThemeService::class));

        $this->expectException(LogicException::class);

        $adapter->execute(
            $this->descriptor('TXBoard', ModuleSource::SYSTEM, true),
            ModuleLifecycleOperation::UNINSTALL,
        );
    }

    private function descriptor(
        string $theme,
        ModuleSource $source,
        bool $active,
    ): ModuleDescriptor {
        return new ModuleDescriptor(
            manifest: ModuleManifest::fromArray([
                'schema' => 1,
                'module' => [
                    'id' => ModuleId::legacy('theme', $theme),
                    'name' => $theme,
                    'version' => '1.0.0',
                    'type' => ModuleType::THEME->value,
                ],
                'compatibility' => ['txboard' => '*'],
                'capabilities' => ['theme'],
            ]),
            source: $source,
            installed: true,
            enabled: true,
            active: $active,
            health: ModuleHealth::HEALTHY,
        );
    }
}
