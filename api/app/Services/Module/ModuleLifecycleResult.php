<?php

namespace App\Services\Module;

final readonly class ModuleLifecycleResult
{
    private function __construct(
        public ModuleLifecycleOperation $operation,
        public bool $success,
        public ?ModuleDescriptor $module,
        public ?ModuleLifecycleError $error,
    ) {
    }

    public static function success(
        ModuleLifecycleOperation $operation,
        ?ModuleDescriptor $module,
    ): self {
        return new self(
            operation: $operation,
            success: true,
            module: $module,
            error: null,
        );
    }

    public static function failure(
        ModuleLifecycleOperation $operation,
        ModuleLifecycleError $error,
        ?ModuleDescriptor $module = null,
    ): self {
        return new self(
            operation: $operation,
            success: false,
            module: $module,
            error: $error,
        );
    }

    public function toArray(): array
    {
        return [
            'operation' => $this->operation->value,
            'success' => $this->success,
            'module' => $this->module?->toArray(),
            'error' => $this->error?->toArray(),
        ];
    }
}
