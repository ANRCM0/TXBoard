<?php

namespace App\Core\Kernel\Modules;

final readonly class CapabilityDefinition
{
    public function __construct(public string $id, public string $contract,
        public int $version = 1, public bool $multiple = false, public bool $exported = true)
    {
        ModuleIdentifier::check($id);
        if ($version < 1 || $version > 65535 || strlen($contract) > 255
            || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+\z/D', $contract) !== 1) {
            throw new ModuleError('MODULE_CAPABILITY_INVALID', capabilityId: $id);
        }
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'version' => $this->version, 'contract' => $this->contract,
            'multiple' => $this->multiple, 'exported' => $this->exported];
    }
}
