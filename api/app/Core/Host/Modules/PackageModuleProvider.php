<?php

namespace App\Core\Host\Modules;

use App\Core\Kernel\Modules\ModuleDefinition;

/** Connector selection remains with its domain registry; packages never replace unique owners. */
final readonly class PackageModuleProvider implements ModuleProvider
{
    public function __construct(private ModuleDefinition $module) {}
    public function definition(): ModuleDefinition { return $this->module; }
    public function bindings(): array { return []; }
}
