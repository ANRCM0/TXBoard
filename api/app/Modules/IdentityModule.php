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
use App\Domains\Identity\Contracts\AccountAdministration;
use App\Domains\Identity\Contracts\AccountPreferences;
use App\Domains\Identity\NativeAccountAdministration;
use App\Domains\Identity\NativeAccountPreferences;

final class IdentityModule implements ModuleProvider
{
    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition('identity', '1.1.0', 'required-business', provides: [
                new CapabilityDefinition('identity.current-session-authorization', \App\Domains\Identity\Internal\CurrentSessionAuthorization::class, exported: false),
                new CapabilityDefinition('identity.account-read', AccountQueries::class),
                new CapabilityDefinition('identity.admin-account-read', \App\Domains\Identity\Contracts\AdminAccountQueries::class, exported: false),
                new CapabilityDefinition('identity.sessions', AccountSessions::class),
                new CapabilityDefinition('identity.accounts', AccountAdministration::class),
                new CapabilityDefinition('identity.preferences', AccountPreferences::class),
                new CapabilityDefinition('identity.initial-preparation', \App\Domains\Identity\Internal\InitialIdentity::class, exported: false),
            ], requires: [new CapabilityRequirement('installation.admission'), new CapabilityRequirement('subscription.entitlements'),
                new CapabilityRequirement('subscription.locked-account-access')], required: true);
    }

    public function bindings(): array
    {
        return [
            new ServiceBinding(\App\Domains\Identity\Internal\CurrentSessionAuthorization::class, \App\Domains\Identity\NativeCurrentSessionAuthorization::class),
            new ServiceBinding(AccountQueries::class, NativeAccountQueries::class),
            new ServiceBinding(\App\Domains\Identity\Contracts\AdminAccountQueries::class, NativeAccountQueries::class),
            new ServiceBinding(AccountSessions::class, NativeAccountSessions::class),
            new ServiceBinding(AccountAdministration::class, NativeAccountAdministration::class),
            new ServiceBinding(AccountPreferences::class, NativeAccountPreferences::class),
            new ServiceBinding(\App\Domains\Identity\Internal\InitialIdentity::class, \App\Domains\Identity\NativeInitialIdentity::class),
        ];
    }
}
