<?php

namespace App\Core\Kernel\Modules;

use RuntimeException;

final class ModuleError extends RuntimeException
{
    public function __construct(public readonly string $errorCode,
        public readonly ?string $moduleId = null, public readonly ?string $capabilityId = null)
    {
        parent::__construct($errorCode);
    }
}
