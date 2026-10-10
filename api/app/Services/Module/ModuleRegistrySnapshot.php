<?php

namespace App\Services\Module;

final readonly class ModuleRegistrySnapshot
{
    /**
     * @param list<ModuleDescriptor> $modules
     * @param list<ModuleDiscoveryError> $errors
     */
    public function __construct(
        public array $modules,
        public array $errors,
    ) {
    }

    public function find(string $id): ?ModuleDescriptor
    {
        foreach ($this->modules as $module) {
            if ($module->manifest->id === $id) {
                return $module;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        $modules = array_map(
            static fn (ModuleDescriptor $module) => $module->toArray(),
            $this->modules,
        );

        $health = [];
        foreach (ModuleHealth::cases() as $case) {
            $health[$case->value] = 0;
        }
        foreach ($this->modules as $module) {
            $health[$module->health->value]++;
        }

        return [
            'modules' => $modules,
            'errors' => array_map(
                static fn (ModuleDiscoveryError $error) => $error->toArray(),
                $this->errors,
            ),
            'summary' => [
                'total' => count($modules),
                'health' => $health,
                'discovery_errors' => count($this->errors),
            ],
        ];
    }
}
