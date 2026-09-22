<?php

namespace App\Services\Module;

interface ModuleLifecycleAdapter
{
    public function name(): string;

    public function supports(ModuleDescriptor $module): bool;

    public function supportsOperation(
        ModuleDescriptor $module,
        ModuleLifecycleOperation $operation,
    ): bool;

    public function expectsModuleAfterOperation(
        ModuleDescriptor $module,
        ModuleLifecycleOperation $operation,
    ): bool;

    public function execute(
        ModuleDescriptor $module,
        ModuleLifecycleOperation $operation,
    ): void;
}
