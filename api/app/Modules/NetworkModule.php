<?php

namespace App\Modules;

use App\Core\Host\Modules\ModuleProvider;
use App\Core\Host\Modules\ServiceBinding;
use App\Core\Kernel\Modules\CapabilityDefinition;
use App\Core\Kernel\Modules\CapabilityRequirement;
use App\Core\Kernel\Modules\ModuleDefinition;
use App\Domains\Network\Contracts\NetworkGroups;
use App\Domains\Network\NetworkGroupReader;

final class NetworkModule implements ModuleProvider
{
    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition('network', '1.0.0', 'required-business', provides: [
                new CapabilityDefinition('network.groups', NetworkGroups::class),
            ], requires: [new CapabilityRequirement('installation.admission'), new CapabilityRequirement('subscription.entitlements'), new CapabilityRequirement('credentials.provider-client')], required: true);
    }

    public function bindings(): array
    {
        return [
            new ServiceBinding(NetworkGroups::class, NetworkGroupReader::class),
        ];
    }
}
