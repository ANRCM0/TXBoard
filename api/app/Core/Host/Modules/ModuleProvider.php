<?php

namespace App\Core\Host\Modules;

use App\Core\Kernel\Modules\ModuleDefinition;

interface ModuleProvider
{
    public function definition(): ModuleDefinition;

    /** @return list<ServiceBinding> Registration is declarative and must not perform I/O. */
    public function bindings(): array;
}
