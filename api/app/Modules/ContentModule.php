<?php

namespace App\Modules;

use App\Core\Host\Modules\ModuleProvider;
use App\Core\Host\Modules\ServiceBinding;
use App\Core\Kernel\Modules\CapabilityDefinition;
use App\Core\Kernel\Modules\CapabilityRequirement;
use App\Core\Kernel\Modules\ModuleDefinition;
use App\Domains\Content\Contracts\ContentQueries;
use App\Domains\Content\Contracts\ContentCommands;
use App\Domains\Content\NativeContentWorkflow;

final class ContentModule implements ModuleProvider
{
    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition('content', '1.0.0', 'required-business', provides: [
            new CapabilityDefinition('content.read', ContentQueries::class, exported: false),
            new CapabilityDefinition('content.write', ContentCommands::class, exported: false),
        ], requires: [new CapabilityRequirement('installation.admission'),
            new CapabilityRequirement('identity.current-session-authorization'),
            new CapabilityRequirement('subscription.locked-entitlements'),
            new CapabilityRequirement('notifications.event-journal'), new CapabilityRequirement('platform.invocation')], required: true);
    }
    public function bindings(): array
    {
        return [new ServiceBinding(ContentQueries::class, NativeContentWorkflow::class),
            new ServiceBinding(ContentCommands::class, NativeContentWorkflow::class)];
    }
}
