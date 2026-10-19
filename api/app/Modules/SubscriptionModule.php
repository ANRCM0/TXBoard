<?php

namespace App\Modules;

use App\Core\Host\Modules\ModuleProvider;
use App\Core\Host\Modules\ServiceBinding;
use App\Core\Kernel\Modules\CapabilityDefinition;
use App\Core\Kernel\Modules\CapabilityRequirement;
use App\Core\Kernel\Modules\ModuleDefinition;
use App\Domains\Subscription\Contracts\EntitlementQueries;
use App\Domains\Subscription\Contracts\OrderFulfillment;
use App\Domains\Subscription\Contracts\CatalogQueries;
use App\Domains\Subscription\Contracts\Purchases;
use App\Domains\Subscription\Contracts\SubscriptionLifecycle;
use App\Domains\Subscription\NativePurchases;
use App\Domains\Subscription\Services\NativePlanCatalog;
use App\Domains\Subscription\Services\SubscriptionLifecycleService;
use App\Domains\Subscription\Internal\LockedEntitlementQueries;
use App\Domains\Subscription\Internal\LockedOrderFulfillment;
use App\Domains\Subscription\Services\SubscriptionEntitlementResolver;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use App\Domains\Subscription\Internal\LockedAccountAccess;
use App\Domains\Subscription\NativeAccountAccess;
use App\Domains\Subscription\Contracts\BrowserDeliveries;
use App\Domains\Subscription\Services\NativeSubscriptionDelivery;

final class SubscriptionModule implements ModuleProvider
{
    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition('subscription', '1.1.0', 'required-business', provides: [
                new CapabilityDefinition('subscription.browser-deliveries', BrowserDeliveries::class, exported: false),
                new CapabilityDefinition('subscription.entitlements', EntitlementQueries::class),
                new CapabilityDefinition('subscription.fulfillment', OrderFulfillment::class),
                new CapabilityDefinition('subscription.catalog', CatalogQueries::class),
                new CapabilityDefinition('subscription.admin-catalog-read', \App\Domains\Subscription\Contracts\AdminCatalogQueries::class, exported: false),
                new CapabilityDefinition('subscription.purchase', Purchases::class),
                new CapabilityDefinition('subscription.coupon-purchase', \App\Domains\Subscription\Contracts\CouponPurchases::class, exported: false),
                new CapabilityDefinition('subscription.lifecycle', SubscriptionLifecycle::class),
                new CapabilityDefinition('subscription.locked-entitlements', LockedEntitlementQueries::class, exported: false),
                new CapabilityDefinition('subscription.locked-fulfillment', LockedOrderFulfillment::class, exported: false),
                new CapabilityDefinition('subscription.locked-account-access', LockedAccountAccess::class, exported: false),
                new CapabilityDefinition('subscription.initial-preparation', \App\Domains\Subscription\Internal\InitialCatalog::class, exported: false),
            ], requires: [new CapabilityRequirement('installation.admission'), new CapabilityRequirement('platform.clock'), new CapabilityRequirement('credentials.provider-client'), new CapabilityRequirement('billing.winning-proof'),
                new CapabilityRequirement('billing.locked-coupon-discounts'), new CapabilityRequirement('billing.commission-terms'), new CapabilityRequirement('identity.current-session-authorization')], required: true);
    }

    public function bindings(): array
    {
        return [
            new ServiceBinding(BrowserDeliveries::class, NativeSubscriptionDelivery::class),
            new ServiceBinding(EntitlementQueries::class, SubscriptionEntitlementResolver::class),
            new ServiceBinding(OrderFulfillment::class, SubscriptionFulfillmentService::class),
            new ServiceBinding(CatalogQueries::class, NativePlanCatalog::class),
            new ServiceBinding(\App\Domains\Subscription\Contracts\AdminCatalogQueries::class, \App\Domains\Subscription\Services\NativeAdminCatalogQueries::class),
            new ServiceBinding(Purchases::class, NativePurchases::class),
            new ServiceBinding(\App\Domains\Subscription\Contracts\CouponPurchases::class, NativePurchases::class),
            new ServiceBinding(SubscriptionLifecycle::class, SubscriptionLifecycleService::class),
            new ServiceBinding(LockedEntitlementQueries::class, SubscriptionEntitlementResolver::class),
            new ServiceBinding(LockedOrderFulfillment::class, SubscriptionFulfillmentService::class),
            new ServiceBinding(LockedAccountAccess::class, NativeAccountAccess::class),
            new ServiceBinding(\App\Domains\Subscription\Internal\InitialCatalog::class, \App\Domains\Subscription\NativeInitialCatalog::class),
        ];
    }
}
