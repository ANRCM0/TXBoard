<?php

namespace App\Providers;

use App\Core\Infrastructure\SystemClock;
use App\Core\Kernel\Clock;
use App\Domains\CredentialProvider\Contracts\ProviderClient;
use App\Domains\CredentialProvider\ProviderTransport;
use App\Domains\Subscription\Contracts\EntitlementQueries;
use App\Domains\Subscription\Internal\LockedEntitlementQueries;
use App\Domains\Subscription\Services\SubscriptionEntitlementResolver;
use Illuminate\Support\ServiceProvider;

final class CoreCapabilitiesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->scoped(ProviderClient::class, ProviderTransport::class);
        $this->app->scoped(SubscriptionEntitlementResolver::class);
        $this->app->scoped(EntitlementQueries::class,
            fn ($app) => $app->make(SubscriptionEntitlementResolver::class));
        $this->app->scoped(LockedEntitlementQueries::class,
            fn ($app) => $app->make(SubscriptionEntitlementResolver::class));
    }
}
