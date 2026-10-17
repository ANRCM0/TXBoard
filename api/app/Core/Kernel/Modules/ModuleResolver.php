<?php

namespace App\Core\Kernel\Modules;

/** Resolves a fixed deployment set without booting code, accessing storage or ordering business calls. */
final class ModuleResolver
{
    /** @param list<ModuleDefinition> $definitions */
    public function compose(array $definitions): ModulePlan
    {
        if (!array_is_list($definitions) || count($definitions) > 128) throw new ModuleError('MODULE_SET_INVALID');
        $active = [];
        $unavailable = [];
        foreach ($definitions as $definition) {
            if (!$definition instanceof ModuleDefinition) throw new ModuleError('MODULE_DEFINITION_INVALID');
            if (isset($active[$definition->id]) || isset($unavailable[$definition->id])) {
                throw new ModuleError('MODULE_ID_CONFLICT', $definition->id);
            }
            if ($definition->minimumKernelApi > ModuleDefinition::KERNEL_API
                || $definition->maximumKernelApi < ModuleDefinition::KERNEL_API) {
                if ($definition->required) throw new ModuleError('MODULE_KERNEL_VERSION_UNSUPPORTED', $definition->id);
                $unavailable[$definition->id] = 'MODULE_KERNEL_VERSION_UNSUPPORTED';
            } else {
                $active[$definition->id] = $definition;
            }
        }
        ksort($active, SORT_STRING);
        do {
            $changed = false;
            $providers = $this->providers($active);
            foreach ($providers as $id => $entries) {
                $first = $entries[0][1];
                $conflict = false;
                foreach ($entries as [, $capability]) {
                    if ($capability->contract !== $first->contract || $capability->version !== $first->version
                        || $capability->multiple !== $first->multiple || !$first->multiple && count($entries) > 1) {
                        $conflict = true;
                    }
                }
                if (!$conflict) continue;
                $required = array_filter($entries, static fn (array $entry): bool => $entry[0]->required);
                if (count($required) > 1) throw new ModuleError('MODULE_CAPABILITY_CONFLICT', capabilityId: $id);
                foreach ($entries as [$module]) {
                    if (!$module->required) {
                        unset($active[$module->id]);
                        $unavailable[$module->id] = 'MODULE_CAPABILITY_CONFLICT';
                        $changed = true;
                    }
                }
            }
            $providers = $this->providers($active);
            foreach ($active as $id => $module) {
                $failure = null;
                foreach ($module->requires as $requirement) {
                    $matches = array_filter($providers[$requirement->id] ?? [],
                        static fn (array $entry): bool => $requirement->accepts($entry[1]));
                    if ($matches === []) { $failure = 'MODULE_DEPENDENCY_UNAVAILABLE'; break; }
                }
                foreach ($module->bootAfter as $after) {
                    if (!isset($active[$after])) $failure = 'MODULE_START_DEPENDENCY_UNAVAILABLE';
                }
                if ($failure === null) continue;
                if ($module->required) {
                    // Optional providers may disappear in this pass. Re-check required modules after closure.
                    continue;
                }
                unset($active[$id]);
                $unavailable[$id] = $failure;
                $changed = true;
            }
        } while ($changed);
        $providers = $this->providers($active);
        foreach ($active as $module) {
            foreach ($module->requires as $requirement) {
                if (!array_filter($providers[$requirement->id] ?? [], static fn (array $entry): bool => $requirement->accepts($entry[1]))) {
                    throw new ModuleError('MODULE_DEPENDENCY_UNAVAILABLE', $module->id, $requirement->id);
                }
            }
            foreach ($module->bootAfter as $after) {
                if (!isset($active[$after])) throw new ModuleError('MODULE_START_DEPENDENCY_UNAVAILABLE', $module->id);
            }
        }
        $ordered = [];
        while ($active !== []) {
            $ready = array_filter($active, static fn (ModuleDefinition $module): bool =>
                array_intersect($module->bootAfter, array_keys($active)) === []);
            if ($ready === []) {
                foreach ($active as $module) {
                    if ($module->required) throw new ModuleError('MODULE_START_CYCLE', $module->id);
                    $unavailable[$module->id] = 'MODULE_START_CYCLE';
                }
                // Re-close capability requirements after removing optional startup cycles.
                $retry = $this->compose($ordered);
                $unavailable = array_replace($unavailable, $retry->unavailable);
                ksort($unavailable, SORT_STRING);
                return new ModulePlan($retry->modules, $unavailable);
            }
            foreach ($ready as $id => $module) { $ordered[] = $module; unset($active[$id]); }
        }
        ksort($unavailable, SORT_STRING);
        return new ModulePlan($ordered, $unavailable);
    }

    private function providers(array $modules): array
    {
        $providers = [];
        foreach ($modules as $module) {
            foreach ($module->provides as $capability) $providers[$capability->id][] = [$module, $capability];
        }
        return $providers;
    }
}
