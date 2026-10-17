<?php

namespace App\Core\Host\Modules;

use App\Core\Host\Contracts\TransportSchema;
use App\Domains\Installation\InstallationAuthorityException;
use App\Domains\Installation\NativeRuntimeReadiness;
use App\Models\Plugin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Derives readiness from the existing registry, plugin state and owner schema/configuration versions. */
final class ModuleReadiness
{
    public function __construct(private readonly ModuleRuntime $runtime, private readonly ModulePackageLock $packages) {}

    public function composition(): array
    {
        $release = config('core_modules.release_id');
        if (!is_string($release) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:@-]{0,127}\z/D', $release) !== 1) $this->fail('RELEASE_ID_INVALID');
        if (config('app.env') === 'production' && in_array($release, ['development', 'unknown'], true)) $this->fail('RELEASE_ID_REQUIRED');
        $file = base_path('resources/contracts/kernel.v1.files.json');
        if (!is_file($file)) $this->fail('KERNEL_MANIFEST_REQUIRED');
        $kernel = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        // Optional degradation cannot change the approved fixed package input or stop unrelated business modules.
        $business = array_values(array_map(static fn ($module) => $module->toArray(), array_filter($this->runtime->plan()->modules,
            static fn ($module) => !str_starts_with($module->id, 'plugin.'))));
        return ['schema' => 'core-runtime-composition/v1', 'kernel_api' => 1, 'release_id' => $release,
            'kernel_manifest' => NativeRuntimeReadiness::hash($kernel), 'business_modules' => NativeRuntimeReadiness::hash($business),
            'package_lock' => $this->packages->fingerprint(), 'packages' => array_values($this->packages->declared())];
    }

    public function inspectLocked(bool $strictDeployment = false): array
    {
        if (DB::transactionLevel() < 1) $this->fail('TRANSACTION_REQUIRED');
        foreach (['tx_module_deployment' => ['deployment_id', 'module_code', 'package_hash', 'phase'],
            'tx_module_event' => ['event_key', 'installation_id', 'content_hash'],
            'tx_module_delivery' => ['event_key', 'module_code', 'package_version', 'configuration_ciphertext', 'lease_token'],
            'tx_module_release' => ['installation_id', 'version', 'composition_fingerprint', 'composition_snapshot', 'content_hash'],
            'tx_plugins' => ['code', 'module_schema_version', 'module_config_version', 'native_payment_mutation_token']] as $table => $columns) {
            if (!Schema::hasColumns($table, $columns)) $this->fail('SCHEMA_REQUIRED');
        }
        $composition = $this->composition();
        $diagnostics = $this->packages->unavailable();
        $active = array_column($this->runtime->plan()->modules, 'id');
        $requiredReady = true;
        foreach ($this->packages->packages() as $code => $package) {
            $row = Plugin::query()->where('code', $code)->sharedLock()->first();
            $state = $row === null ? 'not_installed' : (!$row->is_enabled ? 'disabled' : 'ready');
            if (!in_array('plugin.'.$code, $active, true)) $state = 'unavailable';
            elseif ($row !== null && ($row->version !== $package['version'] || (int) $row->module_schema_version !== $package['manifest']['schema_version'])) {
                $state = 'version_mismatch';
                if ($strictDeployment) $this->fail('PACKAGE_VERSION_UNAVAILABLE');
            } elseif ($row?->native_payment_mutation_token !== null) {
                $state = 'deployment_incomplete';
                if ($strictDeployment) $this->fail('DEPLOYMENT_INCOMPLETE');
            } elseif ($row !== null && $row->is_enabled) {
                try {
                    $configuration = json_decode($row->config ?: '{}', true, 32, JSON_THROW_ON_ERROR);
                    if (!is_array($configuration) || !(new TransportSchema)->accepts($configuration, $package['manifest']['config_schema'])) throw new \RuntimeException;
                    (new ModuleSecrets)->resolve($configuration, $package['manifest']['secrets'] ?? []);
                } catch (\Throwable) { $state = 'configuration_unavailable'; }
            }
            if ($state !== 'ready') $diagnostics['plugin.'.$code] = $state;
            if ($package['required'] && $state !== 'ready') $requiredReady = false;
        }
        if ($strictDeployment && DB::table('tx_module_deployment')->whereNotIn('phase', ['completed', 'rejected'])->sharedLock()->exists()) $this->fail('DEPLOYMENT_INCOMPLETE');
        if (!$requiredReady) $this->fail('REQUIRED_NOT_READY');
        ksort($diagnostics, SORT_STRING);
        return ['composition' => $composition, 'fingerprint' => NativeRuntimeReadiness::hash($composition),
            'composition_ready' => true, 'required_ready' => $requiredReady, 'diagnostics' => $diagnostics];
    }

    public function assertTransitionLocked(?array $previous): void
    {
        if (DB::transactionLevel() < 1) $this->fail('TRANSACTION_REQUIRED');
        $target = $this->packages->declared();
        foreach ($previous['composition']['packages'] ?? [] as $package) {
            $code = $package['code'];
            if (!isset($target[$code])) {
                if (Plugin::query()->where('code', $code)->sharedLock()->exists()
                    || DB::table('tx_payment_dispatch')->where('plugin_code', $code)->sharedLock()->exists()
                    || DB::table('tx_module_delivery')->where('module_code', $code)->whereNotIn('status', ['accepted', 'refused'])->sharedLock()->exists()) {
                    $this->fail('REMOVAL_DRAIN_REQUIRED');
                }
            } elseif ($target[$code]['version'] === $package['version'] && $target[$code]['fingerprint'] !== $package['fingerprint']) {
                $this->fail('PACKAGE_VERSION_REUSED');
            }
        }
    }

    private function fail(string $code): never { throw new InstallationAuthorityException('INSTALLATION_AUTHORITY_MODULE_'.$code); }
}
