<?php

namespace App\Domains\CredentialProviderFixture;

use App\Models\Plugin;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Fixed trusted fixture only. No Node drain receipt or uninstall success can be supplied by a caller. */
final class FixturePluginLifecycleGate
{
    public function __construct(private FixturePluginBinding $bindings, private FixtureLifecycleService $lifecycle) {}

    public function refuseReactivation(): never { throw new DomainException('PROVIDER_PLUGIN_REACTIVATION_UNSUPPORTED'); }

    public function disable(): bool
    {
        $this->bindings->guard(); $this->outsideTransaction();
        return DB::transaction(function () {
            $plugin = $this->plugin();
            [$binding, $registration] = $this->bindings->locked($plugin);
            $wasEnabled = (bool) $plugin->is_enabled;
            $this->lifecycle->fenceLockedRegistration($registration, self::key($binding));
            if (DB::table('tx_credential_provider_shadow_registration')->where('id', $registration->id)->value('enabled')) {
                throw new DomainException('PROVIDER_LIFECYCLE_DAMAGED');
            }
            $plugin->update(['is_enabled' => false, 'updated_at' => now()]);
            return $wasEnabled;
        }, 5);
    }

    public function assertBootable(): void
    {
        $this->bindings->guard(); $this->outsideTransaction();
        DB::transaction(function () {
            $plugin = $this->plugin(); [, $registration] = $this->bindings->locked($plugin, true);
            if (!$plugin->is_enabled || !$registration->enabled) { throw new DomainException('PROVIDER_DISABLED'); }
            FixtureLifecycleService::assertIssuable($registration, '0');
        });
        // Does not cancel already executing PHP or make its boot atomic with disable.
    }

    public function status(): array
    {
        [$provider, $key] = $this->context();
        return $this->lifecycle->status($provider, $key);
    }

    public function cleanup(int $limit, int $deadline): array
    {
        [$provider, $key] = $this->context();
        return $this->lifecycle->cleanup($provider, $key, $limit, $deadline);
    }

    public function refuseRemoval(): never
    {
        $status = $this->status();
        throw new DomainException($status['fixture_cleanup_complete'] ? 'PROVIDER_NODE_DRAIN_UNVERIFIED' : 'PROVIDER_PLUGIN_CLEANUP_PENDING');
    }

    private function context(): array
    {
        $this->bindings->guard(); $this->outsideTransaction();
        return DB::transaction(function () {
            $plugin = $this->plugin(); [$binding, $registration] = $this->bindings->locked($plugin);
            if ($plugin->is_enabled || $registration->enabled) { throw new DomainException('PROVIDER_DISABLE_REQUIRED'); }
            return [$registration->provider_id, self::key($binding)];
        });
    }

    private function plugin(): object
    {
        $plugin = Plugin::query()->where('code', FixturePluginBinding::CODE)->lockForUpdate()->first();
        if (!$plugin) { throw new DomainException('PROVIDER_PLUGIN_INSTALLATION_REQUIRED'); }
        return $plugin;
    }

    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) { throw new DomainException('PROVIDER_OUTER_TRANSACTION_UNSUPPORTED'); }
    }

    private static function key(object $binding): string { return 'fixture_plugin_disable_'.$binding->id; }
}
