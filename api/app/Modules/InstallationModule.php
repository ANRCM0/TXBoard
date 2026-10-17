<?php

namespace App\Modules;

use App\Core\Host\Modules\ModuleProvider;
use App\Core\Host\Modules\ServiceBinding;
use App\Core\Kernel\Modules\CapabilityDefinition;
use App\Core\Kernel\Modules\CapabilityRequirement;
use App\Core\Kernel\Modules\ModuleDefinition;
use App\Domains\Installation\Contracts\InstallationAdmission;
use App\Domains\Installation\InstallationAuthority;

final class InstallationModule implements ModuleProvider
{
    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition('installation', '1.0.0', 'required-business', provides: [
                new CapabilityDefinition('installation.admission', InstallationAdmission::class, exported: false),
            ], requires: [], required: true);
    }

    public function bindings(): array
    {
        return [
            new ServiceBinding(InstallationAdmission::class, InstallationAuthority::class),
        ];
    }
}
