<?php

namespace App\Services\Module;

use Throwable;

final class ModuleRegistry
{
    /**
     * @param list<ModuleAdapter> $adapters
     */
    public function __construct(
        private readonly array $adapters,
    ) {
    }

    public function snapshot(): ModuleRegistrySnapshot
    {
        $modules = [];
        $errors = [];
        $seen = [];

        foreach ($this->adapters as $adapter) {
            try {
                $result = $adapter->discover();
            } catch (Throwable $e) {
                $errors[] = new ModuleDiscoveryError(
                    adapter: $adapter->name(),
                    message: $e->getMessage(),
                );
                continue;
            }

            foreach ($result->errors as $error) {
                $errors[] = $error;
            }

            foreach ($result->modules as $module) {
                $id = $module->manifest->id;

                if (isset($seen[$id])) {
                    $errors[] = new ModuleDiscoveryError(
                        adapter: $adapter->name(),
                        moduleId: $id,
                        message: "Duplicate module ID: {$id}",
                    );
                    continue;
                }

                $seen[$id] = true;
                $modules[] = $module;
            }
        }

        usort(
            $modules,
            static fn (ModuleDescriptor $a, ModuleDescriptor $b) =>
                strcmp($a->manifest->id, $b->manifest->id),
        );

        return new ModuleRegistrySnapshot($modules, $errors);
    }

    public function find(string $id): ?ModuleDescriptor
    {
        return $this->snapshot()->find($id);
    }
}
