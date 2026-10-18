<?php

namespace App\Modules;

use App\Core\Host\Modules\ModuleProvider;
use App\Core\Host\Modules\ServiceBinding;
use App\Core\Kernel\Modules\CapabilityDefinition;
use App\Core\Kernel\Modules\CapabilityRequirement;
use App\Core\Kernel\Modules\ModuleDefinition;
use App\Domains\Billing\Contracts\BillingReconciliation;
use App\Domains\Billing\Contracts\OrderQueries;
use App\Domains\Billing\Contracts\PaymentCheckout;
use App\Domains\Billing\Contracts\WalletPayments;
use App\Domains\Billing\Contracts\WalletRefunds;
use App\Domains\Billing\Contracts\Recharges;
use App\Domains\Billing\Contracts\PaymentMethods;
use App\Domains\Billing\Contracts\OrderCancellation;
use App\Domains\Billing\Contracts\OrderCheckout;
use App\Domains\Billing\NativeRechargeService;
use App\Domains\Billing\NativePaymentMethods;
use App\Domains\Billing\NativeOrderCancellation;
use App\Domains\Billing\Internal\WinningPaymentProofs;
use App\Domains\Billing\NativeBillingReconciliation;
use App\Domains\Billing\NativeOrderQueries;
use App\Domains\Billing\NativePaymentCheckout;
use App\Domains\Billing\NativePaymentSettlement;
use App\Domains\Billing\NativeRefundService;
use App\Domains\Billing\NativeWalletPayments;

final class BillingModule implements ModuleProvider
{
    public function definition(): ModuleDefinition
    {
        return new ModuleDefinition('billing', '1.0.0', 'required-business', provides: [
                new CapabilityDefinition('billing.orders-read', OrderQueries::class),
                new CapabilityDefinition('billing.checkout', PaymentCheckout::class),
                new CapabilityDefinition('billing.wallet', WalletPayments::class),
                new CapabilityDefinition('billing.wallet-refunds', WalletRefunds::class),
                new CapabilityDefinition('billing.reconciliation', BillingReconciliation::class),
                new CapabilityDefinition('billing.recharges', Recharges::class),
                new CapabilityDefinition('billing.payment-methods', PaymentMethods::class),
                new CapabilityDefinition('billing.order-cancellation', OrderCancellation::class),
                new CapabilityDefinition('billing.order-checkout', OrderCheckout::class),
                new CapabilityDefinition('billing.winning-proof', WinningPaymentProofs::class, exported: false),
                new CapabilityDefinition('billing.package-references', \App\Domains\Billing\Internal\PaymentPackageReferences::class, exported: false),
            ], requires: [new CapabilityRequirement('installation.admission'), new CapabilityRequirement('subscription.fulfillment'),
                new CapabilityRequirement('notifications.event-journal')], required: true);
    }

    public function bindings(): array
    {
        return [
            new ServiceBinding(OrderQueries::class, NativeOrderQueries::class),
            new ServiceBinding(PaymentCheckout::class, NativePaymentCheckout::class),
            new ServiceBinding(WalletPayments::class, NativeWalletPayments::class),
            new ServiceBinding(WalletRefunds::class, NativeRefundService::class),
            new ServiceBinding(BillingReconciliation::class, NativeBillingReconciliation::class),
            new ServiceBinding(Recharges::class, NativeRechargeService::class),
            new ServiceBinding(PaymentMethods::class, NativePaymentMethods::class),
            new ServiceBinding(OrderCancellation::class, NativeOrderCancellation::class),
            new ServiceBinding(OrderCheckout::class, \App\Domains\Billing\OrderCheckout::class),
            new ServiceBinding(WinningPaymentProofs::class, NativePaymentSettlement::class),
            new ServiceBinding(\App\Domains\Billing\Internal\PaymentPackageReferences::class, \App\Domains\Billing\NativePaymentPackageReferences::class),
        ];
    }
}
