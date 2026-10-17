<?php

namespace App\Modules;

use App\Core\Host\Modules\ModuleProvider;
use App\Core\Host\Modules\ServiceBinding;
use App\Core\Kernel\Modules\CapabilityDefinition;
use App\Core\Kernel\Modules\CapabilityRequirement;
use App\Core\Kernel\Modules\ModuleDefinition;
use App\Domains\Identity\Contracts\AccountQueries;
use App\Domains\Identity\Contracts\AccountSessions;
use App\Domains\Identity\NativeAccountQueries;
use App\Domains\Identity\NativeAccountSessions;

final class IdentityModule implements ModuleProvider
{
    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition('identity', '1.0.0', 'required-business', provides: [
                new CapabilityDefinition('identity.account-read', AccountQueries::class),
                new CapabilityDefinition('identity.sessions', AccountSessions::class),
            ], requires: [new CapabilityRequirement('installation.admission'), new CapabilityRequirement('subscription.entitlements')], required: true);
    }

    public function bindings(): array
    {
        return [
            new ServiceBinding(AccountQueries::class, NativeAccountQueries::class),
            new ServiceBinding(AccountSessions::class, NativeAccountSessions::class),
        ];
    }
}
