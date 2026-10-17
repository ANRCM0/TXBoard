<?php

namespace App\Core\Host\Modules;

use App\Core\Kernel\Modules\ModuleError;

final readonly class ServiceBinding
{
    public function __construct(public string $contract, public string $implementation,
        public bool $singleton = false)
    {
        if ($contract === '' || $implementation === '') throw new ModuleError('MODULE_BINDING_INVALID');
    }
}
