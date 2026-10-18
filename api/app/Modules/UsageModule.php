<?php

namespace App\Modules;

use App\Core\Host\Modules\ModuleProvider;
use App\Core\Host\Modules\ServiceBinding;
use App\Core\Kernel\Modules\CapabilityDefinition;
use App\Core\Kernel\Modules\CapabilityRequirement;
use App\Core\Kernel\Modules\ModuleDefinition;
use App\Domains\UsageV2\Contracts\UsageQueries;
use App\Domains\UsageV2\NativeUsageQueries;

final class UsageModule implements ModuleProvider
{
    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition('usage', '1.0.0', 'required-business', provides: [
                new CapabilityDefinition('usage.read', UsageQueries::class),
            ], requires: [new CapabilityRequirement('installation.admission'), new CapabilityRequirement('subscription.entitlements')], required: true);
    }

    public function bindings(): array
    {
        return [
            new ServiceBinding(UsageQueries::class, NativeUsageQueries::class),
        ];
    }
}
