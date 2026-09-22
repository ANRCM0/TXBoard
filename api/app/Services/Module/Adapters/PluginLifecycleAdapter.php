<?php

namespace App\Services\Module\Adapters;

use App\Services\Module\ModuleDescriptor;
use App\Services\Module\ModuleLifecycleAdapter;
use App\Services\Module\ModuleLifecycleOperation;
use App\Services\Module\ModuleType;
use App\Services\Plugin\PluginManager;
use LogicException;

final class PluginLifecycleAdapter implements ModuleLifecycleAdapter
{
    public function __construct(
        private readonly PluginManager $pluginManager,
    ) {
    }

    public function name(): string
    {
        return 'plugin';
    }

    public function supports(ModuleDescriptor $module): bool
    {
        return $module->manifest->type === ModuleType::PLUGIN;
    }

    public function execute(
        ModuleDescriptor $module,
        ModuleLifecycleOperation $operation,
    ): void {
        if (!$this->supports($module)) {
            throw new LogicException('PluginLifecycleAdapter only supports plugin modules');
        }

        $pluginCode = $module->manifest->id;

        match ($operation) {
            ModuleLifecycleOperation::INSTALL => $this->pluginManager->install($pluginCode),
            ModuleLifecycleOperation::ENABLE => $this->pluginManager->enable($pluginCode),
            ModuleLifecycleOperation::DISABLE => $this->pluginManager->disable($pluginCode),
            ModuleLifecycleOperation::UPGRADE => $this->pluginManager->update($pluginCode),
            ModuleLifecycleOperation::UNINSTALL => $this->pluginManager->uninstall($pluginCode),
        };
    }
}
