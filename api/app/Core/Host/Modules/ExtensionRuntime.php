<?php

namespace App\Core\Host\Modules;

use App\Core\Host\Contracts\TransportSchema;
use App\Core\Kernel\Modules\ModuleError;
use App\Domains\Installation\Contracts\InstallationAdmission;
use App\Extensions\Contracts\ExtensionFailure;
use App\Extensions\Contracts\VersionedModule;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\PluginManager;
use App\Models\Plugin;

final class ExtensionRuntime
{
    public function __construct(private readonly ModulePackageLock $packages, private readonly ModuleRuntime $runtime,
        private readonly PluginManager $plugins, private readonly InstallationAdmission $installation) {}

    public function package(string $code, ?string $expectedFingerprint = null): array
    {
        $package = $this->packages->package($code);
        if ($package === null || !in_array('plugin.'.$code, array_column($this->runtime->plan()->modules, 'id'), true)) {
            throw new ExtensionFailure('MODULE_NOT_AVAILABLE', 404);
        }
        if ($expectedFingerprint !== null && !hash_equals($package['fingerprint'], $expectedFingerprint)) throw new ExtensionFailure('MODULE_VERSION_CHANGED');
        return $package;
    }

    public function instance(string $code, string $contract, bool $recovery = false): AbstractPlugin
    {
        $package = $this->package($code);
        $this->admission($code, $recovery);
        $plugin = $this->plugins->moduleInstance($code);
        $version = $plugin instanceof VersionedModule ? $plugin->packageVersion()
            : ($plugin instanceof \App\Contracts\NativePaymentPluginInterface ? $plugin->nativePaymentPackageVersion() : null);
        if (!$plugin instanceof $contract || $version !== $package['version']) throw new ModuleError('MODULE_LOADED_VERSION_MISMATCH', 'plugin.'.$code);
        $this->validateConfiguration($package, $plugin->getConfig());
        $plugin->setConfig((new ModuleSecrets)->resolve($plugin->getConfig(), $package['manifest']['secrets'] ?? []));
        return $plugin;
    }

    public function admission(string $code, bool $recovery = false): void
    {
        $package = $this->package($code);
        $this->installation->assertNativeRuntime();
        $row = Plugin::query()->useWritePdo()->where('code', $code)->first();
        if ($row === null || (!$row->is_enabled && !$recovery) || $row->native_payment_mutation_token !== null
            || $row->version !== $package['version'] || (int) $row->module_schema_version !== $package['manifest']['schema_version']) {
            throw new ExtensionFailure('MODULE_NOT_AVAILABLE', 409);
        }
        if (realpath($this->plugins->getPluginPath($code)) !== realpath($package['path'])) throw new ExtensionFailure('MODULE_PACKAGE_PATH_CONFLICT');
        $this->dependencies($code);
    }

    public function dependencies(string $code): void
    {
        $package = $this->package($code);
        foreach ($package['definition']->requires as $requirement) {
            foreach ($this->runtime->plan()->providers($requirement->id) as $provider) {
                if (!str_starts_with($provider, 'plugin.')) continue;
                $dependency = $this->packages->package(substr($provider, 7));
                $state = Plugin::query()->useWritePdo()->where('code', $dependency['code'])->first();
                if ($state === null || !$state->is_enabled || $state->native_payment_mutation_token !== null
                    || $state->version !== $dependency['version'] || (int) $state->module_schema_version !== $dependency['manifest']['schema_version']) {
                    throw new ExtensionFailure('MODULE_DEPENDENCY_UNAVAILABLE', 503);
                }
            }
        }
    }

    public function validateConfiguration(array $package, array $configuration): void
    {
        if (!(new TransportSchema)->accepts($configuration, $package['manifest']['config_schema'])) throw new ExtensionFailure('MODULE_CONFIGURATION_INVALID');
        (new ModuleSecrets)->resolve($configuration, $package['manifest']['secrets'] ?? []);
    }
}
