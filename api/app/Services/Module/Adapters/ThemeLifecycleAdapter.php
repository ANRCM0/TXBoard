<?php

namespace App\Services\Module\Adapters;

use App\Services\Module\ModuleDescriptor;
use App\Services\Module\ModuleId;
use App\Services\Module\ModuleLifecycleAdapter;
use App\Services\Module\ModuleLifecycleOperation;
use App\Services\Module\ModuleSource;
use App\Services\Module\ModuleType;
use App\Services\ThemeService;
use LogicException;
use RuntimeException;

final class ThemeLifecycleAdapter implements ModuleLifecycleAdapter
{
    public function __construct(
        private readonly ThemeService $themes,
    ) {
    }

    public function name(): string
    {
        return 'theme';
    }

    public function supports(ModuleDescriptor $module): bool
    {
        return $module->manifest->type === ModuleType::THEME;
    }

    public function supportsOperation(
        ModuleDescriptor $module,
        ModuleLifecycleOperation $operation,
    ): bool {
        if (!$this->supports($module)) {
            return false;
        }

        return match ($operation) {
            ModuleLifecycleOperation::ENABLE => $module->active !== true,
            ModuleLifecycleOperation::UNINSTALL =>
                $module->source === ModuleSource::USER && $module->active !== true,
            default => false,
        };
    }

    public function expectsModuleAfterOperation(
        ModuleDescriptor $module,
        ModuleLifecycleOperation $operation,
    ): bool {
        return $operation !== ModuleLifecycleOperation::UNINSTALL;
    }

    public function execute(
        ModuleDescriptor $module,
        ModuleLifecycleOperation $operation,
    ): void {
        if (!$this->supportsOperation($module, $operation)) {
            throw new LogicException('Theme lifecycle operation is not supported');
        }

        $theme = $this->themeName($module);

        match ($operation) {
            ModuleLifecycleOperation::ENABLE => $this->themes->switch($theme),
            ModuleLifecycleOperation::UNINSTALL => $this->themes->delete($theme),
            default => throw new LogicException('Theme lifecycle operation is not supported'),
        };
    }

    private function themeName(ModuleDescriptor $module): string
    {
        foreach (array_keys($this->themes->getList()) as $theme) {
            $name = (string) $theme;
            if (ModuleId::legacy('theme', $name) === $module->manifest->id) {
                return $name;
            }
        }

        throw new RuntimeException('Theme runtime identity could not be resolved');
    }
}
