<?php

namespace App\Core\Kernel\Modules;

final readonly class ModulePlan
{
    /** @param list<ModuleDefinition> $modules @param array<string,string> $unavailable */
    public function __construct(public array $modules, public array $unavailable) {}

    public function fingerprint(): string
    {
        return hash('sha256', json_encode(array_map(static fn (ModuleDefinition $module): array => $module->toArray(),
            $this->modules), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** This is discovery only; it never authorizes an operation or constructs an implementation. */
    public function providers(string $capabilityId): array
    {
        $ids = [];
        foreach ($this->modules as $module) {
            foreach ($module->provides as $capability) {
                if ($capability->id === $capabilityId) $ids[] = $module->id;
            }
        }
        return $ids;
    }
}
