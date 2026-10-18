<?php

namespace App\Core\Kernel\Modules;

final readonly class ModuleDefinition
{
    public const KERNEL_API = 1;
    public const KINDS = ['required-business', 'optional-business', 'connector', 'presentation', 'platform'];

    /** @param list<CapabilityDefinition> $provides @param list<CapabilityRequirement> $requires @param list<string> $bootAfter */
    public function __construct(public string $id, public string $version, public string $kind,
        public array $provides = [], public array $requires = [], public array $bootAfter = [],
        public bool $required = false, public int $minimumKernelApi = 1, public int $maximumKernelApi = 1)
    {
        ModuleIdentifier::check($id);
        if (!in_array($kind, self::KINDS, true) || strlen($version) > 80
            || preg_match('/\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?\z/D', $version) !== 1
            || $minimumKernelApi < 1 || $maximumKernelApi < $minimumKernelApi
            || !array_is_list($provides) || !array_is_list($requires) || !array_is_list($bootAfter)
            || count($provides) > 64 || count($requires) > 64 || count($bootAfter) > 64) {
            throw new ModuleError('MODULE_DEFINITION_INVALID', $id);
        }
        $seen = [];
        foreach ($provides as $capability) {
            if (!$capability instanceof CapabilityDefinition || isset($seen[$capability->id])) {
                throw new ModuleError('MODULE_CAPABILITY_INVALID', $id);
            }
            $seen[$capability->id] = true;
        }
        $seen = [];
        foreach ($requires as $requirement) {
            if (!$requirement instanceof CapabilityRequirement || isset($seen[$requirement->id])) {
                throw new ModuleError('MODULE_REQUIREMENT_INVALID', $id);
            }
            $seen[$requirement->id] = true;
        }
        foreach ($bootAfter as $dependency) {
            if (!is_string($dependency)) throw new ModuleError('MODULE_START_ORDER_INVALID', $id);
            ModuleIdentifier::check($dependency);
        }
        if (count(array_unique($bootAfter)) !== count($bootAfter)) {
            throw new ModuleError('MODULE_START_ORDER_INVALID', $id);
        }
    }

    public function toArray(bool $publicOnly = false): array
    {
        return ['id' => $this->id, 'version' => $this->version, 'kind' => $this->kind,
            'required' => $this->required, 'kernel_api' => ['min' => $this->minimumKernelApi, 'max' => $this->maximumKernelApi],
            'provides' => array_values(array_map(static fn (CapabilityDefinition $capability): array => $capability->toArray(),
                array_filter($this->provides, static fn (CapabilityDefinition $capability): bool => !$publicOnly || $capability->exported))),
            'requires' => array_map(static fn (CapabilityRequirement $requirement): array => [
                'id' => $requirement->id, 'min' => $requirement->minimumVersion, 'max' => $requirement->maximumVersion,
            ], $this->requires), 'boot_after' => $this->bootAfter];
    }
}
