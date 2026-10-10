<?php

namespace App\Services\Module;

final readonly class ModuleDiscoveryResult
{
    /**
     * @param list<ModuleDescriptor> $modules
     * @param list<ModuleDiscoveryError> $errors
     */
    public function __construct(
        public array $modules = [],
        public array $errors = [],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }
}
