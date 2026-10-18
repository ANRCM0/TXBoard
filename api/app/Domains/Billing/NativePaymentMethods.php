<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Contracts\PaymentMethods;
use App\Domains\Installation\Contracts\InstallationAdmission;
use App\Models\Payment;

final class NativePaymentMethods implements PaymentMethods
{
    public function __construct(private readonly InstallationAdmission $installation,
        private readonly NativePaymentProviderRegistry $providers) {}

    public function available(): array
    {
        $this->installation->assertNativeRuntime();
        return Payment::query()->where('enable', true)
            ->where(fn ($query) => $query->where('handling_fee_fixed', 0)->orWhereNull('handling_fee_fixed'))
            ->where(fn ($query) => $query->where('handling_fee_percent', 0)->orWhereNull('handling_fee_percent'))->orderBy('sort')->orderBy('id')
            ->limit(100)->get()->filter(function (Payment $method): bool {
                try {
                    $provider = $this->providers->forPayment($method);
                    $provider->adapter->accountRef($method);
                    return in_array('CNY', $provider->adapter->capabilities()['currencies'], true);
                } catch (BillingError) { return false; }
            })->map(static fn (Payment $method): array => [
                'id' => (string) $method->id, 'name' => (string) $method->name,
                'provider' => (string) $method->payment, 'icon' => $method->icon,
                'currency' => 'CNY', 'fee_fixed_minor' => '0', 'fee_percent' => '0',
                'external_refund_supported' => false,
            ])->values()->all();
    }
}
