<?php

namespace App\Extensions\SDK;

use App\Core\Host\Modules\ExtensionRuntime;
use App\Core\Host\Modules\ModuleRuntime;
use App\Domains\Billing\Contracts\OrderCheckout;
use App\Domains\Billing\Contracts\OrderQueries;
use App\Domains\Subscription\Contracts\CatalogQueries;
use App\Domains\Subscription\Contracts\Purchases;
use App\Extensions\Contracts\ExtensionFailure;

/** A convenience facade for trusted PHP packages, not a sandbox or an actor authorization grant. */
final class Capabilities
{
    public function __construct(private readonly string $module) {}

    public function purchases(): Purchases { return $this->service('subscription.purchase', Purchases::class); }
    public function catalog(): CatalogQueries { return $this->service('subscription.catalog', CatalogQueries::class); }
    public function checkout(): OrderCheckout { return $this->service('billing.order-checkout', OrderCheckout::class); }
    public function orders(): OrderQueries { return $this->service('billing.orders-read', OrderQueries::class); }

    /** For a module-owned SQL transaction only. Never hold this boundary across an owner command or network call. */
    public function admitLocked(bool $recovery = false): array
    {
        $installation = app(\App\Domains\Installation\Contracts\InstallationAdmission::class)->assertNativeMutation();
        $package = app(ExtensionRuntime::class)->package($this->module);
        $row = \App\Models\Plugin::query()->where('code', $this->module)->sharedLock()->first();
        if ($row === null || (!$recovery && !$row->is_enabled) || $row->native_payment_mutation_token !== null
            || $row->version !== $package['version'] || (int) $row->module_schema_version !== $package['manifest']['schema_version']) {
            throw new ExtensionFailure('MODULE_NOT_AVAILABLE');
        }
        return $installation;
    }

    private function service(string $id, string $contract): object
    {
        $package = app(ExtensionRuntime::class)->package($this->module);
        if (!in_array('capability:'.$id, $package['grants'], true)
            || !in_array($id, array_column($package['manifest']['requires'], 'id'), true)) throw new ExtensionFailure('MODULE_CAPABILITY_NOT_GRANTED', 403);
        foreach (app(ModuleRuntime::class)->plan()->modules as $module) {
            foreach ($module->provides as $capability) {
                if ($capability->id === $id && $capability->exported && !$capability->multiple && $capability->contract === $contract) return app($contract);
            }
        }
        throw new ExtensionFailure('MODULE_CAPABILITY_UNAVAILABLE', 503);
    }
}
