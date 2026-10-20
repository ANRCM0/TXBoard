<?php

namespace App\Domains\Platform;

use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\Installation\Contracts\InstallationAdmission;
use App\Extensions\Contracts\ExtensionFailure;
use App\Models\Setting;
use App\Models\User;
use App\Services\Module\ModuleId;
use App\Services\NativeSettingsStorage;
use App\Services\Theme\ThemeV2Runtime;
use App\Services\ThemeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Durable browser intents surround the existing static-package and theme-selection owners. */
final class NativeThemeDeployment
{
    public function __construct(private readonly ThemeService $themes, private readonly ThemeV2Runtime $runtime,
        private readonly InstallationAdmission $installation) {}

    public function card(User $actor, string $id): array
    {
        $name = $this->name($id);
        $installation = $this->installation->assertNativeRead();
        $state = $this->state($name, $installation['installation_id']);
        $actions = [];
        if (NativeModuleActor::permitted($actor, 'platform.modules.manage') && $state['active'] !== $name) {
            $actions[] = 'enable';
            if ($name !== 'TXBoard') $actions[] = 'uninstall';
        }
        return ['fingerprint' => $state['fingerprint'], 'revision' => $state['revision'], 'actions' => $actions];
    }

    public function catalog(User $actor): array
    {
        return $this->runtime->withConfigurationLock(fn () => DB::transaction(function () use ($actor): array {
            $installation = $this->authorize($actor, false);
            $result = [];
            foreach (array_keys(config('theme_v2.deployment_candidates', [])) as $id) {
                $candidate = $this->candidate($id);
                $state = $this->state($candidate['name'], $installation['installation_id']);
                $eligible = $state['version'] === null || version_compare($candidate['version'], $state['version'], '>');
                $result[] = ['id' => $id, 'module_id' => ModuleId::theme($candidate['name']), 'title' => $candidate['title'], 'version' => $candidate['version'],
                    'sha256' => $candidate['sha256'], 'expected_revision' => $state['revision'], 'action' => $state['version'] === null ? 'install' : 'upgrade',
                    'eligible' => $eligible, 'installed_version' => $state['version']];
            }
            return ['candidates' => $result];
        }));
    }

    public function deploy(User $actor, array $input, string $key): array
    {
        return $this->execute($actor, 'package:'.$input['candidate_id'], $input, $key, function (array $installation) use ($input): array {
            $candidate = $this->candidate($input['candidate_id']);
            if (!hash_equals($candidate['sha256'], $input['sha256'])) throw new ExtensionFailure('MODULE_STATE_CHANGED');
            $state = $this->state($candidate['name'], $installation['installation_id']);
            if (!hash_equals($state['revision'], $input['expected_revision'])) throw new ExtensionFailure('MODULE_STATE_CHANGED');
            $action = $state['version'] === null ? 'install' : 'upgrade';
            if ($input['action'] !== $action || $state['version'] !== null && !version_compare($candidate['version'], $state['version'], '>')) {
                throw new ExtensionFailure('MODULE_STATE_CHANGED');
            }
            return ['name' => $candidate['name'], 'action' => $action, 'before_revision' => $state['revision'],
                'target' => array_intersect_key($candidate, array_flip(['name', 'version', 'sha256', 'manifest_hash'])) + ['candidate_id' => $input['candidate_id']]];
        });
    }

    public function lifecycle(User $actor, array $input, string $key): array
    {
        return $this->execute($actor, 'lifecycle:'.$input['module_id'], $input, $key, function (array $installation) use ($input): array {
            $name = $this->name($input['module_id']);
            $state = $this->state($name, $installation['installation_id']);
            if (!hash_equals($state['fingerprint'], $input['fingerprint']) || !hash_equals($state['revision'], $input['expected_revision'])) throw new ExtensionFailure('MODULE_STATE_CHANGED');
            if (!in_array($input['action'], ['enable', 'uninstall'], true)) throw new ExtensionFailure('MODULE_DEPLOYMENT_INPUT_INVALID', 422);
            if ($input['action'] === 'uninstall' && ($name === 'TXBoard' || $state['active'] === $name)) throw new ExtensionFailure('MODULE_PROTECTED');
            if ($input['action'] === 'enable' && $state['active'] === $name) throw new ExtensionFailure('MODULE_STATE_CHANGED');
            return ['name' => $name, 'action' => $input['action'], 'before_revision' => $state['revision'],
                'target' => ['name' => $name, 'fingerprint' => $state['fingerprint'], 'version' => $state['version']]];
        });
    }

    private function execute(User $actor, string $scope, array $input, string $key, \Closure $prepare): array
    {
        return $this->runtime->withConfigurationLock(function () use ($actor, $scope, $input, $key, $prepare): array {
            $inputHash = hash('sha256', ProviderCodec::canonical($input));
            $intent = DB::transaction(function () use ($actor, $scope, $inputHash, $key, $prepare): object {
                $installation = $this->authorize($actor, true);
                app(NativeSettingsStorage::class)->lock();
                $hash = hash('sha256', 'native-theme-deployment/v1:'.$installation['installation_id'].':'.$actor->id.':'.$scope.':'.$key);
                $old = DB::table('tx_native_theme_deployment')->where('key_hash', $hash)->lockForUpdate()->first();
                if ($old !== null) {
                    if (!hash_equals($old->input_hash, $inputHash)) throw new ExtensionFailure('MODULE_DEPLOYMENT_CONFLICT');
                    return $old;
                }
                $spec = $prepare($installation);
                $moduleId = ModuleId::theme($spec['name']);
                if (DB::table('tx_native_theme_deployment')->where('installation_id', $installation['installation_id'])
                    ->where('phase', '!=', 'completed')->exists()) throw new ExtensionFailure('MODULE_DEPLOYMENT_IN_PROGRESS');
                $row = ['key_hash' => $hash, 'installation_id' => $installation['installation_id'], 'actor_id' => $actor->id, 'module_id' => $moduleId,
                    'action' => $spec['action'], 'input_hash' => $inputHash, 'before_revision' => $spec['before_revision'],
                    'target_json' => json_encode($spec['target'], JSON_THROW_ON_ERROR), 'phase' => 'prepared', 'created_at' => time(), 'updated_at' => time()];
                DB::table('tx_native_theme_deployment')->insert($row);
                return (object) $row;
            });
            return DB::transaction(function () use ($actor, $intent): array {
                $installation = $this->authorize($actor, true);
                app(NativeSettingsStorage::class)->lock();
                $row = DB::table('tx_native_theme_deployment')->where('key_hash', $intent->key_hash)->lockForUpdate()->first();
                if ($row->installation_id !== $installation['installation_id'] || (string) $row->actor_id !== (string) $actor->id) throw new ExtensionFailure('MODULE_DEPLOYMENT_CONFLICT');
                $result = ['module_id' => $row->module_id, 'phase' => 'completed', 'action' => $row->action];
                if ($row->phase === 'completed') return $result;
                $target = json_decode($row->target_json, true, 16, JSON_THROW_ON_ERROR);
                $state = $this->state($target['name'], $installation['installation_id']);
                if (!$this->effect($row->action, $target, $state)) {
                    if (!hash_equals($row->before_revision, $state['revision'])) throw new ExtensionFailure('MODULE_RECOVERY_REQUIRED');
                    $this->apply($row->action, $target);
                    $state = $this->state($target['name'], $installation['installation_id']);
                    if (!$this->effect($row->action, $target, $state)) throw new ExtensionFailure('MODULE_RECOVERY_REQUIRED');
                }
                // An interrupted deletion may have removed files before its settings transaction committed.
                if ($row->action === 'uninstall') $this->themes->finalizeDeleted($target['name']);
                DB::table('tx_native_theme_deployment')->where('key_hash', $row->key_hash)->update(['phase' => 'completed', 'updated_at' => time()]);
                return $result;
            });
        });
    }

    private function apply(string $action, array $target): void
    {
        try {
            if ($action === 'enable') { $this->themes->switch($target['name']); return; }
            if ($action === 'uninstall') { $this->themes->delete($target['name']); return; }
            $candidate = $this->candidate($target['candidate_id']);
            if (!hash_equals($target['sha256'], $candidate['sha256']) || !hash_equals($target['manifest_hash'], $candidate['manifest_hash'])) throw new ExtensionFailure('MODULE_STATE_CHANGED');
            $snapshot = tempnam(storage_path('framework'), 'native-theme-');
            if ($snapshot === false) throw new ExtensionFailure('MODULE_PACKAGE_UNAVAILABLE', 503);
            try {
                $source = fopen($candidate['path'], 'rb'); $destination = fopen($snapshot, 'wb');
                if ($source === false || $destination === false) {
                    if (is_resource($source)) fclose($source);
                    if (is_resource($destination)) fclose($destination);
                    throw new ExtensionFailure('MODULE_PACKAGE_UNAVAILABLE', 503);
                }
                try { $bytes = stream_copy_to_stream($source, $destination, 32 * 1024 * 1024 + 1); }
                finally { fclose($source); fclose($destination); }
                if ($bytes === false || $bytes > 32 * 1024 * 1024 || !hash_equals($target['sha256'], hash_file('sha256', $snapshot))) {
                    throw new ExtensionFailure('MODULE_STATE_CHANGED');
                }
                $manifest = $this->runtime->inspectDeploymentPackage($snapshot);
                if (!hash_equals($target['manifest_hash'], hash('sha256', ProviderCodec::canonical($manifest)))) throw new ExtensionFailure('MODULE_STATE_CHANGED');
                $this->runtime->install(new UploadedFile($snapshot, 'controlled-theme.zip', 'application/zip', null, true));
            } finally { if (is_file($snapshot)) unlink($snapshot); }
        } catch (\InvalidArgumentException) { throw new ExtensionFailure('MODULE_PACKAGE_INVALID', 422); }
    }

    private function effect(string $action, array $target, array $state): bool
    {
        if ($action === 'uninstall') return $state['version'] === null && !$state['directory_present'] && $state['active'] !== $target['name'];
        if ($action === 'enable') {
            if ($state['active'] !== $target['name'] || !hash_equals($state['fingerprint'], $target['fingerprint'])) return false;
            if ($target['name'] !== 'TXBoard') $this->verify($target['name']);
            return true;
        }
        if ($state['version'] !== $target['version'] || $state['manifest_hash'] !== $target['manifest_hash']) return false;
        $this->verify($target['name']);
        return true;
    }

    private function verify(string $name): void
    {
        try { $this->runtime->verifyInstalled($name); }
        catch (\InvalidArgumentException) { throw new ExtensionFailure('MODULE_PACKAGE_INVALID', 422); }
    }

    private function candidate(string $id): array
    {
        $spec = config('theme_v2.deployment_candidates.'.$id);
        if (!is_array($spec) || !is_string($spec['path'] ?? null) || !is_string($spec['sha256'] ?? null)) throw new ExtensionFailure('MODULE_PACKAGE_NOT_FOUND', 404);
        $path = realpath($spec['path']);
        if ($path === false || !is_file($path) || is_link($spec['path']) || filesize($path) > 32 * 1024 * 1024
            || !hash_equals($spec['sha256'], hash_file('sha256', $path))) throw new ExtensionFailure('MODULE_PACKAGE_INVALID', 422);
        try { $manifest = $this->runtime->inspectDeploymentPackage($path); }
        catch (\InvalidArgumentException) { throw new ExtensionFailure('MODULE_PACKAGE_INVALID', 422); }
        return ['name' => $manifest['name'], 'title' => mb_substr($manifest['title'] ?? $manifest['name'], 0, 120), 'version' => $manifest['version'],
            'sha256' => $spec['sha256'], 'manifest_hash' => hash('sha256', ProviderCodec::canonical($manifest)), 'path' => $path];
    }

    private function state(string $name, string $installationId): array
    {
        $manifest = $name === 'TXBoard' ? $this->themes->getList()['TXBoard'] : ($this->runtime->isInstalled($name) ? $this->runtime->manifest($name) : null);
        $manifestHash = $manifest === null ? null : hash('sha256', ProviderCodec::canonical($manifest));
        $fingerprint = hash('sha256', ProviderCodec::canonical([$name, $manifestHash]));
        $active = $this->themes->getActiveTheme();
        $saved = Setting::query()->useWritePdo()->where('name', 'theme_'.$name)->first()?->value;
        $directory = $name === 'TXBoard' || is_dir(base_path('storage/theme/'.$name));
        $revision = hash_hmac('sha256', ProviderCodec::canonical([$installationId, $name, $fingerprint, $active, $saved, $directory]), (string) config('app.key'));
        return ['fingerprint' => $fingerprint, 'manifest_hash' => $manifestHash, 'revision' => $revision, 'active' => $active,
            'version' => $manifest['version'] ?? null, 'directory_present' => $directory];
    }

    private function name(string $id): string
    {
        foreach (array_keys($this->themes->getList()) as $name) if (ModuleId::theme((string) $name) === $id) return (string) $name;
        throw new ExtensionFailure('MODULE_NOT_FOUND', 404);
    }

    private function authorize(User $actor, bool $mutation): array
    {
        $installation = $mutation ? $this->installation->assertNativeMutation() : $this->installation->assertNativeRead();
        NativeModuleActor::assertLocked($actor, ['platform.modules.read', 'platform.modules.manage']);
        return $installation;
    }
}
