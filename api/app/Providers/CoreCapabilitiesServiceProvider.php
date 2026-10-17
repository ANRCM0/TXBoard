<?php

namespace App\Providers;

use App\Core\Infrastructure\SystemClock;
use App\Core\Kernel\Clock;
use App\Domains\Billing\Internal\WinningPaymentProofs;
use App\Domains\Billing\Contracts\OrderQueries;
use App\Domains\Billing\NativeOrderQueries;
use App\Domains\Billing\NativePaymentSettlement;
use App\Domains\CredentialProvider\Contracts\ProviderClient;
use App\Domains\CredentialProvider\ProviderTransport;
use App\Domains\Identity\Contracts\AccountQueries;
use App\Domains\Identity\Contracts\AccountSessions;
use App\Domains\Identity\NativeAccountQueries;
use App\Domains\Identity\NativeAccountSessions;
use App\Domains\Subscription\Contracts\EntitlementQueries;
use App\Domains\Subscription\Contracts\OrderFulfillment;
use App\Domains\Subscription\Internal\LockedEntitlementQueries;
use App\Domains\Subscription\Internal\LockedOrderFulfillment;
use App\Domains\Subscription\Services\SubscriptionEntitlementResolver;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use App\Domains\UsageV2\Contracts\UsageQueries;
use App\Domains\UsageV2\NativeUsageQueries;
use Illuminate\Support\ServiceProvider;

final class CoreCapabilitiesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->scoped(ProviderClient::class, ProviderTransport::class);
        $this->app->scoped(AccountQueries::class, NativeAccountQueries::class);
        $this->app->scoped(AccountSessions::class, NativeAccountSessions::class);
        $this->app->scoped(OrderQueries::class, NativeOrderQueries::class);
        $this->app->scoped(UsageQueries::class, NativeUsageQueries::class);
        $this->app->scoped(SubscriptionFulfillmentService::class);
        $this->app->scoped(OrderFulfillment::class,
            fn ($app) => $app->make(SubscriptionFulfillmentService::class));
        $this->app->scoped(LockedOrderFulfillment::class,
            fn ($app) => $app->make(SubscriptionFulfillmentService::class));
        $this->app->scoped(NativePaymentSettlement::class);
        $this->app->scoped(WinningPaymentProofs::class,
            fn ($app) => $app->make(NativePaymentSettlement::class));
        $this->app->scoped(SubscriptionEntitlementResolver::class);
        $this->app->scoped(EntitlementQueries::class,
            fn ($app) => $app->make(SubscriptionEntitlementResolver::class));
        $this->app->scoped(LockedEntitlementQueries::class,
            fn ($app) => $app->make(SubscriptionEntitlementResolver::class));
    }
}
