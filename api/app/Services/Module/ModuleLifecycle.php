<?php

namespace App\Services\Module;

use Throwable;

final class ModuleLifecycle
{
    /**
     * @param list<ModuleLifecycleAdapter> $adapters
     */
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly array $adapters,
    ) {
    }

    public function execute(
        string $moduleId,
        ModuleLifecycleOperation $operation,
    ): ModuleLifecycleResult {
        $module = $this->modules->find($moduleId);
        if (!$module) {
            return ModuleLifecycleResult::failure(
                operation: $operation,
                error: new ModuleLifecycleError(
                    code: ModuleLifecycleErrorCode::MODULE_NOT_FOUND,
                    message: 'Module not found',
                ),
            );
        }

        $adapter = $this->adapterFor($module);
        if (!$adapter) {
            return ModuleLifecycleResult::failure(
                operation: $operation,
                module: $module,
                error: new ModuleLifecycleError(
                    code: ModuleLifecycleErrorCode::UNSUPPORTED_MODULE_TYPE,
                    message: 'Lifecycle operation is not supported for this module type',
                ),
            );
        }

        try {
            $adapter->execute($module, $operation);
        } catch (Throwable) {
            return ModuleLifecycleResult::failure(
                operation: $operation,
                module: $this->modules->find($moduleId) ?? $module,
                error: new ModuleLifecycleError(
                    code: ModuleLifecycleErrorCode::RUNTIME_ERROR,
                    message: 'Module lifecycle runtime failed',
                    adapter: $adapter->name(),
                ),
            );
        }

        $refreshed = $this->modules->find($moduleId);
        if (!$refreshed) {
            return ModuleLifecycleResult::failure(
                operation: $operation,
                error: new ModuleLifecycleError(
                    code: ModuleLifecycleErrorCode::STATE_REFRESH_FAILED,
                    message: 'Module state could not be refreshed',
                    adapter: $adapter->name(),
                ),
            );
        }

        return ModuleLifecycleResult::success($operation, $refreshed);
    }

    private function adapterFor(ModuleDescriptor $module): ?ModuleLifecycleAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($module)) {
                return $adapter;
            }
        }

        return null;
    }
}
