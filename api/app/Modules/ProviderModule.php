<?php

namespace App\Modules;

use App\Core\Host\Modules\ModuleProvider;
use App\Core\Host\Modules\ServiceBinding;
use App\Core\Kernel\Modules\CapabilityDefinition;
use App\Core\Kernel\Modules\CapabilityRequirement;
use App\Core\Kernel\Modules\ModuleDefinition;
use App\Domains\CredentialProvider\Contracts\ProviderClient;
use App\Domains\CredentialProvider\ProviderTransport;

final class ProviderModule implements ModuleProvider
{
    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition('provider', '1.0.0', 'connector', provides: [
                new CapabilityDefinition('credentials.provider-client', ProviderClient::class, exported: false),
                new CapabilityDefinition('credentials.template-validator', \App\Domains\CredentialProvider\Contracts\TemplateValidationClient::class, exported: false),
            ], requires: [new CapabilityRequirement('installation.admission')], required: true);
    }

    public function bindings(): array
    {
        return [
            new ServiceBinding(ProviderClient::class, ProviderTransport::class),
            new ServiceBinding(\App\Domains\CredentialProvider\Contracts\TemplateValidationClient::class, ProviderTransport::class),
        ];
    }
}
