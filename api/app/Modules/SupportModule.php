<?php

namespace App\Modules;

use App\Core\Host\Modules\ModuleProvider;
use App\Core\Host\Modules\ServiceBinding;
use App\Core\Kernel\Modules\CapabilityDefinition;
use App\Core\Kernel\Modules\CapabilityRequirement;
use App\Core\Kernel\Modules\ModuleDefinition;
use App\Domains\Support\Contracts\TicketCommands;
use App\Domains\Support\Contracts\TicketQueries;
use App\Domains\Support\NativeTicketWorkflow;
use App\Domains\Support\Contracts\TicketAttachments;
use App\Domains\Support\NativeTicketAttachments;

final class SupportModule implements ModuleProvider
{
    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition('support', '1.0.0', 'required-business', provides: [
            new CapabilityDefinition('support.tickets.read', TicketQueries::class, exported: false),
            new CapabilityDefinition('support.tickets.write', TicketCommands::class, exported: false),
            new CapabilityDefinition('support.attachments', TicketAttachments::class, exported: false),
        ], requires: [new CapabilityRequirement('installation.admission'),
            new CapabilityRequirement('identity.current-session-authorization'),
            new CapabilityRequirement('notifications.event-journal'),
            new CapabilityRequirement('platform.invocation')], required: true);
    }

    public function bindings(): array
    {
        return [new ServiceBinding(TicketQueries::class, NativeTicketWorkflow::class),
            new ServiceBinding(TicketCommands::class, NativeTicketWorkflow::class),
            new ServiceBinding(TicketAttachments::class, NativeTicketAttachments::class)];
    }
}
