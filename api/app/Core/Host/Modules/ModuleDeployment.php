<?php

namespace App\Core\Host\Modules;

use App\Core\Host\Contracts\TransportSchema;
use App\Domains\Installation\Contracts\InstallationAdmission;
use App\Extensions\Contracts\DeploymentModule;
use App\Extensions\Contracts\ExtensionFailure;
use App\Extensions\Contracts\RemovalGuard;
use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Owns deployment intent and recovery; tx_plugins remains the sole installed/enabled authority. */
final class ModuleDeployment
{
    public function __construct(private readonly ExtensionRuntime $runtime, private readonly ModuleExecutionLock $lock,
        private readonly InstallationAdmission $installation, private readonly PluginManager $plugins) {}

    public function execute(string $code, string $action, string $deploymentId, ?array $configuration = null,
        ?array $expected = null, ?\Closure $authorize = null, ?\Closure $configurationProvider = null): array
    {
        if (!Str::isUuid($deploymentId) || !in_array($action, ['install', 'enable', 'disable', 'update', 'configure', 'uninstall'], true)
            || ($action === 'configure') !== ($configuration !== null)) throw new ExtensionFailure('MODULE_DEPLOYMENT_INPUT_INVALID', 422);
        if ($expected !== null && (array_keys($expected) !== ['fingerprint', 'revision', 'input_hash']
            || !is_string($expected['fingerprint']) || !is_string($expected['revision']) || !is_string($expected['input_hash'])
            || preg_match('/\A[0-9a-f]{64}\z/D', $expected['fingerprint']) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $expected['revision']) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $expected['input_hash']) !== 1)) throw new ExtensionFailure('MODULE_DEPLOYMENT_INPUT_INVALID', 422);
        if ($configurationProvider !== null && ($expected === null || $action !== 'configure')) throw new ExtensionFailure('MODULE_DEPLOYMENT_INPUT_INVALID', 422);
        $package = $this->runtime->package($code, $expected['fingerprint'] ?? null);
        if (($package['manifest']['lifecycle'] ?? 'managed') !== 'managed') throw new ExtensionFailure('MODULE_LIFECYCLE_OWNED_BY_PAYMENT');
        if ($configuration !== null && strlen(json_encode($configuration, JSON_THROW_ON_ERROR)) > 32768) throw new ExtensionFailure('MODULE_CONFIGURATION_INVALID', 422);
        $installation = DB::transaction(fn (): array => $this->installation->assertModuleDeployment(), 3);
        $requestHash = hash('sha256', json_encode($expected === null ? [$action, $configuration] : [$action, $expected], JSON_THROW_ON_ERROR));
        return $this->lock->run($code, function () use ($code, $action, $deploymentId, $configuration, $package, $installation, $requestHash, $expected, $authorize, $configurationProvider): array {
            $intent = DB::transaction(function () use ($code, $action, $deploymentId, $configuration, $package, $installation, $requestHash, $expected, $authorize, $configurationProvider): object {
                $currentInstallation = $this->installation->assertModuleDeployment();
                if ($authorize !== null) $authorize($currentInstallation);
                DB::table('tx_plugin_package_fence')->insertOrIgnore(['plugin_code' => $code, 'mutation_token' => null]);
                $fence = DB::table('tx_plugin_package_fence')->where('plugin_code', $code)->lockForUpdate()->first();
                $row = Plugin::query()->where('code', $code)->lockForUpdate()->first();
                $intent = DB::table('tx_module_deployment')->where('deployment_id', $deploymentId)->lockForUpdate()->first();
                if ($intent !== null) {
                    if ($intent->module_code !== $code || $intent->action !== $action || $intent->request_hash !== $requestHash
                        || $intent->package_hash !== $package['fingerprint'] || $intent->installation_id !== $installation['installation_id']) {
                        throw new ExtensionFailure('MODULE_DEPLOYMENT_CONFLICT');
                    }
                    if (!in_array($intent->phase, ['completed', 'rejected'], true) && $fence->mutation_token !== $intent->mutation_token) throw new ExtensionFailure('MODULE_DEPLOYMENT_FENCE_LOST');
                    return $intent;
                }
                if ($expected !== null && !hash_equals($expected['revision'], ModuleDeploymentRevision::of($package, $row))) {
                    throw new ExtensionFailure('MODULE_STATE_CHANGED');
                }
                if ($fence->mutation_token !== null || $row?->native_payment_mutation_token !== null) throw new ExtensionFailure('MODULE_DEPLOYMENT_BUSY');
                if ($action === 'enable') $this->runtime->dependencies($code);
                if (in_array($action, ['disable', 'update', 'uninstall'], true)) $this->assertNoEnabledDependents($package);
                if (($action === 'install') !== ($row === null)) throw new ExtensionFailure('MODULE_INSTALLATION_CONFLICT');
                if (in_array($action, ['update', 'uninstall'], true)) $this->assertReferencesDrained($code);
                if ($action === 'uninstall' && $row->is_enabled) throw new ExtensionFailure('MODULE_STOP_BEFORE_REMOVAL');
                if ($action === 'update' && ($row->is_enabled || version_compare($package['version'], $row->version, '<')
                    || $row->version === $package['version'] && (int) $row->module_schema_version >= $package['manifest']['schema_version'])) {
                    throw new ExtensionFailure('MODULE_STOP_BEFORE_UPDATE');
                }
                if (!in_array($action, ['update', 'disable'], true) && $row !== null && $row->version !== $package['version']) throw new ExtensionFailure('MODULE_PACKAGE_VERSION_CONFLICT');
                if ($row !== null && !in_array($action, ['update', 'disable'], true) && (int) $row->module_schema_version !== $package['manifest']['schema_version']) {
                    throw new ExtensionFailure('MODULE_SCHEMA_VERSION_CONFLICT');
                }
                if ((int) ($row?->module_schema_version ?? 0) > $package['manifest']['schema_version']) throw new ExtensionFailure('MODULE_SCHEMA_DOWNGRADE_FORBIDDEN');
                $saved = $configurationProvider !== null ? $configurationProvider($row, $package, $currentInstallation)
                    : ($configuration ?? ($row === null ? $this->plugins->moduleDefaultConfiguration($code) : json_decode($row->config ?: '{}', true, 32, JSON_THROW_ON_ERROR)));
                if (!is_array($saved) || strlen(json_encode($saved, JSON_THROW_ON_ERROR)) > 32768) throw new ExtensionFailure('MODULE_CONFIGURATION_INVALID', 422);
                if (in_array($action, ['enable', 'configure'], true)) $this->runtime->validateConfiguration($package, $saved);
                $token = bin2hex(random_bytes(32));
                $snapshot = ['deployment_id' => $deploymentId, 'module_code' => $code, 'package_hash' => $package['fingerprint'],
                    'request_hash' => $requestHash, 'from_schema' => (int) ($row?->module_schema_version ?? 0), 'configuration' => $saved,
                    'configuration_version' => (int) ($row?->module_config_version ?? 1) + ($action === 'configure' ? 1 : 0),
                    'expected' => $expected];
                $values = ['deployment_id' => $deploymentId, 'installation_id' => $installation['installation_id'],
                    'module_code' => $code, 'action' => $action, 'package_version' => $package['version'],
                    'schema_version' => $package['manifest']['schema_version'], 'package_hash' => $package['fingerprint'],
                    'request_hash' => $requestHash, 'mutation_token' => $token, 'phase' => 'prepared',
                    'state_snapshot' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)),
                    'error_code' => null, 'created_at' => time(), 'updated_at' => time()];
                DB::table('tx_module_deployment')->insert($values);
                DB::table('tx_plugin_package_fence')->where('plugin_code', $code)->update(['mutation_token' => $token]);
                if ($row !== null) $row->update(['native_payment_mutation_token' => $token]);
                return (object) $values;
            }, 3);
            if ($intent->phase === 'completed') return $this->result($intent);
            if ($intent->phase === 'rejected') throw new ExtensionFailure('MODULE_DEPLOYMENT_REJECTED');
            try {
                $snapshot = json_decode(Crypt::decryptString($intent->state_snapshot), true, 32, JSON_THROW_ON_ERROR);
                foreach (['deployment_id', 'module_code', 'package_hash', 'request_hash'] as $field) {
                    if (($snapshot[$field] ?? null) !== $intent->$field) throw new ExtensionFailure('MODULE_DEPLOYMENT_EVIDENCE_INVALID');
                }
                $plugin = $this->plugins->moduleImplementation($code);
                try {
                    if (!$plugin instanceof DeploymentModule || $plugin->packageVersion() !== $package['version']) throw new ExtensionFailure('MODULE_DEPLOYMENT_CONTRACT_REQUIRED');
                    if (in_array('domain', array_column($package['manifest']['operations'], 'idempotency'), true) && !$plugin instanceof RemovalGuard) throw new ExtensionFailure('MODULE_REMOVAL_GUARD_REQUIRED');
                    if ($intent->phase === 'prepared' && in_array($action, ['update', 'uninstall'], true) && $plugin instanceof RemovalGuard) $plugin->assertDrained();
                } catch (ExtensionFailure $error) {
                    if ($intent->phase === 'prepared') $this->rejectPrepared($intent, $error->errorCode);
                    throw $error;
                }
                if (in_array($action, ['install', 'update'], true) && in_array($intent->phase, ['prepared', 'schema_applying'], true)) {
                    // The contract explicitly permits replay with the original ID, including after partial DDL.
                    $this->phase($intent, 'schema_applying');
                    $plugin->prepareSchema($deploymentId, $snapshot['from_schema'], $package['manifest']['schema_version']);
                    $this->phase($intent, 'schema_ready');
                }
                if ($intent->phase === 'prepared') $this->phase($intent, 'schema_ready');
                if ($intent->phase === 'schema_ready') {
                    if ($action !== 'uninstall') $this->plugins->publishAssets($code);
                    else $this->plugins->moduleRemoveAssets($code);
                    $this->phase($intent, 'assets_ready');
                }
                DB::transaction(function () use ($intent, $package, $snapshot, $action, $code, $authorize): void {
                    $state = $this->installation->assertModuleDeployment();
                    if ($authorize !== null) $authorize($state);
                    if ($state['installation_id'] !== $intent->installation_id) throw new ExtensionFailure('MODULE_INSTALLATION_CONFLICT');
                    $fence = DB::table('tx_plugin_package_fence')->where('plugin_code', $code)->lockForUpdate()->first();
                    if ($fence?->mutation_token !== $intent->mutation_token) throw new ExtensionFailure('MODULE_DEPLOYMENT_FENCE_LOST');
                    $row = Plugin::query()->where('code', $code)->lockForUpdate()->first();
                    if (in_array($action, ['update', 'uninstall'], true)) $this->assertReferencesDrained($code);
                    if ($action === 'uninstall') {
                        $row?->delete();
                    } else {
                        $config = json_decode(file_get_contents($package['path'].'/config.json'), true, 32, JSON_THROW_ON_ERROR);
                        $values = ['version' => $action === 'disable' ? $row->version : $package['version'], 'module_schema_version' => $action === 'disable' ? (int) $row->module_schema_version : $package['manifest']['schema_version'],
                            'module_config_version' => $snapshot['configuration_version'],
                            'config' => json_encode($snapshot['configuration'], JSON_THROW_ON_ERROR), 'native_payment_mutation_token' => null];
                        if ($action === 'install') Plugin::create($values + ['code' => $code, 'name' => $config['name'],
                            'type' => $config['type'] ?? Plugin::TYPE_FEATURE, 'is_enabled' => false, 'installed_at' => now()]);
                        else $row->update($values + ['is_enabled' => match ($action) { 'enable' => true, 'disable', 'update' => false, default => $row->is_enabled }]);
                    }
                    $this->phase($intent, 'completed');
                    DB::table('tx_plugin_package_fence')->where('plugin_code', $code)->update(['mutation_token' => null]);
                }, 3);
                return $this->result($intent);
            } catch (\Throwable $error) {
                // Keep the fence, original package identity and durable intent for explicit same-operation recovery.
                DB::table('tx_module_deployment')->where('deployment_id', $deploymentId)->where('phase', '<>', 'rejected')
                    ->update(['error_code' => 'MODULE_DEPLOYMENT_INCOMPLETE', 'updated_at' => time()]);
                throw $error;
            }
        });
    }

    private function rejectPrepared(object $intent, string $code): void
    {
        DB::transaction(function () use ($intent, $code): void {
            $fence = DB::table('tx_plugin_package_fence')->where('plugin_code', $intent->module_code)->lockForUpdate()->first();
            if ($fence?->mutation_token !== $intent->mutation_token) throw new ExtensionFailure('MODULE_DEPLOYMENT_FENCE_LOST');
            DB::table('tx_plugins')->where('code', $intent->module_code)->where('native_payment_mutation_token', $intent->mutation_token)
                ->update(['native_payment_mutation_token' => null]);
            DB::table('tx_plugin_package_fence')->where('plugin_code', $intent->module_code)->update(['mutation_token' => null]);
            DB::table('tx_module_deployment')->where('deployment_id', $intent->deployment_id)->where('phase', 'prepared')
                ->update(['phase' => 'rejected', 'error_code' => $code, 'updated_at' => time()]);
            $intent->phase = 'rejected';
        }, 3);
    }

    private function assertReferencesDrained(string $code): void
    {
        if (app(\App\Domains\Billing\Internal\PaymentPackageReferences::class)->retained($code)
            || DB::table('tx_module_delivery')->where('module_code', $code)->whereNotIn('status', ['accepted', 'refused'])->exists()) {
            throw new ExtensionFailure('MODULE_DRAIN_REQUIRED');
        }
    }

    public function assertDrained(string $code): array
    {
        $package = $this->runtime->package($code);
        return $this->lock->run($code, function () use ($code, $package): array {
            DB::transaction(function () use ($code): void {
                $this->installation->assertModuleDeployment();
                $row = Plugin::query()->where('code', $code)->sharedLock()->first();
                if ($row === null || $row->is_enabled || $row->native_payment_mutation_token !== null) throw new ExtensionFailure('MODULE_STOP_BEFORE_REMOVAL');
                $this->assertReferencesDrained($code);
            }, 3);
            $implementation = $this->plugins->moduleImplementation($code);
            if (!$implementation instanceof DeploymentModule || $implementation->packageVersion() !== $package['version']) throw new ExtensionFailure('MODULE_DEPLOYMENT_CONTRACT_REQUIRED');
            if (in_array('domain', array_column($package['manifest']['operations'], 'idempotency'), true) && !$implementation instanceof RemovalGuard) throw new ExtensionFailure('MODULE_REMOVAL_GUARD_REQUIRED');
            if ($implementation instanceof RemovalGuard) $implementation->assertDrained();
            return ['module' => $code, 'drained' => true, 'package_version' => $package['version']];
        });
    }

    private function assertNoEnabledDependents(array $package): void
    {
        $provided = array_map(static fn ($capability) => $capability->id, $package['definition']->provides);
        foreach (app(ModulePackageLock::class)->packages() as $code => $candidate) {
            if ($code !== $package['code'] && array_intersect($provided, array_column($candidate['manifest']['requires'], 'id'))
                && Plugin::query()->where('code', $code)->where('is_enabled', true)->exists()) throw new ExtensionFailure('MODULE_DEPENDENT_ENABLED');
        }
    }

    public function inspect(string $deploymentId): array
    {
        $intent = DB::table('tx_module_deployment')->useWritePdo()->where('deployment_id', $deploymentId)->first();
        if ($intent === null) throw new ExtensionFailure('MODULE_DEPLOYMENT_NOT_FOUND', 404);
        return $this->result($intent) + ['error_code' => $intent->error_code];
    }

    public function resume(string $code, string $deploymentId): array
    {
        $intent = DB::table('tx_module_deployment')->useWritePdo()->where('deployment_id', $deploymentId)->where('module_code', $code)->first();
        if ($intent === null) throw new ExtensionFailure('MODULE_DEPLOYMENT_NOT_FOUND', 404);
        $snapshot = json_decode(Crypt::decryptString($intent->state_snapshot), true, 32, JSON_THROW_ON_ERROR);
        return $this->execute($code, $intent->action, $deploymentId, $intent->action === 'configure' ? $snapshot['configuration'] : null,
            $snapshot['expected'] ?? null);
    }

    private function phase(object $intent, string $phase): void
    {
        DB::table('tx_module_deployment')->where('deployment_id', $intent->deployment_id)->where('mutation_token', $intent->mutation_token)
            ->update(['phase' => $phase, 'error_code' => null, 'updated_at' => time()]);
        $intent->phase = $phase;
    }

    private function result(object $intent): array
    {
        return ['deployment_id' => $intent->deployment_id, 'module' => $intent->module_code, 'action' => $intent->action,
            'phase' => $intent->phase, 'package_version' => $intent->package_version];
    }
}
