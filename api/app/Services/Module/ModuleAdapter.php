<?php

namespace App\Services\Module;

interface ModuleAdapter
{
    public function name(): string;

    public function discover(): ModuleDiscoveryResult;
}
