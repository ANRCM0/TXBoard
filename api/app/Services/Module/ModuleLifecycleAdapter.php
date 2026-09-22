<?php

namespace App\Services\Module;

interface ModuleLifecycleAdapter
{
    public function name(): string;

    public function supports(ModuleDescriptor $module): bool;

    public function execute(
        ModuleDescriptor $module,
        ModuleLifecycleOperation $operation,
    ): void;
}
