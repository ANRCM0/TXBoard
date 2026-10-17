<?php

namespace App\Modules;

use App\Core\Host\Modules\ModuleProvider;
use App\Core\Host\Modules\ServiceBinding;
use App\Core\Host\Execution\InvocationScope;
use App\Core\Kernel\Execution\CurrentInvocation;
use App\Core\Infrastructure\SystemClock;
use App\Core\Kernel\Clock;
use App\Core\Kernel\Modules\CapabilityDefinition;
use App\Core\Kernel\Modules\CapabilityRequirement;
use App\Core\Kernel\Modules\ModuleDefinition;

final class PlatformModule implements ModuleProvider
{
    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition('platform', '1.0.0', 'platform', provides: [
                new CapabilityDefinition('platform.clock', Clock::class),
                new CapabilityDefinition('platform.invocation', CurrentInvocation::class),
            ], requires: [], required: true);
    }

    public function bindings(): array
    {
        return [
            new ServiceBinding(Clock::class, SystemClock::class, singleton: true),
            new ServiceBinding(CurrentInvocation::class, InvocationScope::class),
        ];
    }
}
