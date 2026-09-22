<?php

namespace App\Services\Module;

final readonly class ModuleDiscoveryError
{
    public function __construct(
        public string $adapter,
        public string $message,
        public ?string $moduleId = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'adapter' => $this->adapter,
            'module_id' => $this->moduleId,
            'message' => $this->message,
        ];
    }
}
