<?php

namespace App\Core\Kernel\Modules;

final readonly class CapabilityRequirement
{
    public function __construct(public string $id, public int $minimumVersion = 1,
        public int $maximumVersion = 1)
    {
        ModuleIdentifier::check($id);
        if ($minimumVersion < 1 || $maximumVersion < $minimumVersion || $maximumVersion > 65535) {
            throw new ModuleError('MODULE_REQUIREMENT_INVALID', capabilityId: $id);
        }
    }

    public function accepts(CapabilityDefinition $capability): bool
    {
        return $capability->id === $this->id && $capability->version >= $this->minimumVersion
            && $capability->version <= $this->maximumVersion;
    }
}
