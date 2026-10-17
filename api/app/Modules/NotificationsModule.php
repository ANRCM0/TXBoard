<?php

namespace App\Modules;

use App\Core\Host\Modules\ModuleProvider;
use App\Core\Host\Modules\ModuleEventJournal;
use App\Core\Host\Modules\ServiceBinding;
use App\Core\Kernel\Modules\CapabilityDefinition;
use App\Core\Kernel\Modules\ModuleDefinition;
use App\Domains\Notifications\Internal\EventJournal;

final class NotificationsModule implements ModuleProvider
{
    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition('notifications', '1.0.0', 'required-business', provides: [
            new CapabilityDefinition('notifications.event-journal', EventJournal::class, exported: false),
        ], required: true);
    }

    public function bindings(): array { return [new ServiceBinding(EventJournal::class, ModuleEventJournal::class)]; }
}
