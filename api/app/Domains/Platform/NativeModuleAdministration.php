<?php

namespace App\Domains\Platform;

use App\Core\Host\Contracts\TransportSchema;
use App\Core\Host\Modules\ExtensionRuntime;
use App\Core\Host\Modules\ModuleDeployment;
use App\Core\Host\Modules\ModuleDeploymentRevision;
use App\Core\Host\Modules\ModulePackageLock;
use App\Domains\Installation\Contracts\InstallationAdmission;
use App\Domains\InternalApi\StrictJson;
use App\Domains\CredentialProvider\ProviderCodec;
use App\Extensions\Contracts\ExtensionFailure;
use App\Models\Plugin;
use App\Models\User;
use App\Services\Module\ModuleDescriptor;
use App\Services\Module\ModuleRegistry;
use App\Services\Plugin\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Browser projections and admission only. Existing deployment owns all installed/enabled/configuration writes. */
final class NativeModuleAdministration
{
    public function __construct(private readonly ModuleRegistry $modules, private readonly PluginManager $plugins,
        private readonly ModulePackageLock $packages, private readonly ExtensionRuntime $runtime,
        private readonly ModuleDeployment $deployments, private readonly InstallationAdmission $installation) {}

    public function index(User $actor): array
    {
        return DB::transaction(function () use ($actor): array {
            $this->installation->assertNativeRead();
            NativeModuleActor::assertLocked($actor, ['platform.modules.read']);
            $snapshot = $this->modules->snapshot();
            if (count($snapshot->modules) > 200) throw new ExtensionFailure('MODULE_LIST_LIMIT', 422);
            return ['modules' => array_map(fn (ModuleDescriptor $module): array => $this->card($module, $actor), $snapshot->modules),
                'discovery_errors' => count($snapshot->errors)];
        });
    }

    public function detail(User $actor, string $id): array
    {
        return DB::transaction(function () use ($actor, $id): array {
            $this->installation->assertNativeRead();
            NativeModuleActor::assertLocked($actor, ['platform.modules.read']);
            $module = $this->modules->find($id) ?? throw new ExtensionFailure('MODULE_NOT_FOUND', 404);
            $readme = '';
            if ($module->manifest->type->value === 'plugin') {
                $directory = $this->plugins->resolvePluginPath($id);
                if ($directory !== null) foreach (['README.md', 'readme.md'] as $name) {
                    $file = $directory.'/'.$name;
                    if (is_file($file) && !is_link($file) && filesize($file) <= 65536) {
                        $readme = mb_convert_encoding(file_get_contents($file), 'UTF-8', 'UTF-8');
                        break;
                    }
                }
            }
            return ['module' => $this->card($module, $actor), 'readme' => $readme];
        });
    }

    public function configuration(User $actor, string $id): array
    {
        if (str_starts_with($id, 'theme.')) return app(NativeThemeConfiguration::class)->configuration($actor, $id);
        return DB::transaction(function () use ($actor, $id): array {
            $this->installation->assertNativeRead();
            $package = $this->managed($id);
            NativeModuleActor::assertLocked($actor, $this->configurationPermissions($package));
            $row = Plugin::query()->useWritePdo()->where('code', $id)->sharedLock()->first()
                ?? throw new ExtensionFailure('MODULE_NOT_FOUND', 404);
            $saved = StrictJson::object($row->config ?: '{}');
            $schema = $package['manifest']['config_schema'];
            $fields = $this->privateFields($package);
            if (!$this->editableSchema($schema, $fields)) throw new ExtensionFailure('MODULE_CONFIGURATION_NOT_AVAILABLE', 409);
            $credentials = [];
            foreach ($fields as $field) {
                if (in_array($field, $package['manifest']['secrets'] ?? [], true)) {
                    $credentials[] = ['name' => $field, 'configured' => isset($saved[$field]) && $saved[$field] !== ''];
                }
                unset($saved[$field], $schema['properties'][$field]);
            }
            $schema['required'] = array_values(array_diff($schema['required'] ?? [], $fields));
            if (ModulePublicProjection::data($saved) !== $saved) throw new ExtensionFailure('MODULE_CONFIGURATION_NOT_AVAILABLE', 409);
            return ['module_id' => $id, 'fingerprint' => $package['fingerprint'], 'revision' => ModuleDeploymentRevision::of($package, $row),
                'schema_json' => $this->json(TransportSchema::wireDefinition($schema), 32768),
                'configuration_json' => $this->json((object) ModulePublicProjection::data($saved), 32768), 'credentials' => $credentials];
        });
    }

    public function saveConfiguration(User $actor, array $input, string $key): array
    {
        if (str_starts_with($input['module_id'], 'theme.')) return app(NativeThemeConfiguration::class)->save($actor, $input, $key);
        $id = $input['module_id'];
        $package = $this->managed($id, $input['fingerprint']);
        $permissions = $this->configurationPermissions($package);
        $fields = $package['manifest']['secrets'] ?? [];
        $privateFields = $this->privateFields($package);
        if (!$this->editableSchema($package['manifest']['config_schema'], $privateFields)) throw new ExtensionFailure('MODULE_CONFIGURATION_NOT_AVAILABLE', 409);
        $public = StrictJson::object($input['configuration_json']);
        if (array_intersect(array_keys($public), $privateFields)) throw new ExtensionFailure('MODULE_CONFIGURATION_INVALID', 422);
        if (ModulePublicProjection::data($public) !== $public) throw new ExtensionFailure('MODULE_CONFIGURATION_INVALID', 422);
        $changes = $input['credential_references'];
        $authorize = static function (array $installation) use ($actor, $permissions, $changes, $fields, $id): void {
            NativeModuleActor::assertLocked($actor, $permissions);
            app(ModuleConfigurationReferences::class)->replace([], $changes, $fields, $actor, $installation['installation_id'], $id);
        };
        $provider = static function (?Plugin $row, array $package, array $installation) use ($public, $privateFields, $changes, $fields, $actor, $id): array {
            if ($row === null) throw new ExtensionFailure('MODULE_NOT_FOUND', 404);
            $private = array_intersect_key(StrictJson::object($row->config ?: '{}'), array_flip($privateFields));
            $private = app(ModuleConfigurationReferences::class)->replace($private, $changes, $fields, $actor, $installation['installation_id'], $id);
            $configuration = array_replace($public, $private);
            if (!(new TransportSchema)->accepts($configuration, $package['manifest']['config_schema'])) throw new ExtensionFailure('MODULE_CONFIGURATION_INVALID', 422);
            return $configuration;
        };
        $result = $this->deployments->execute($id, 'configure', NativeModuleActor::deploymentId($actor, $id, 'configure', $key), [],
            $this->expected($input), $authorize, $provider);
        return ['module_id' => $id, 'phase' => $result['phase'], 'action' => 'configure'];
    }

    public function lifecycle(User $actor, array $input, string $key): array
    {
        if (str_starts_with($input['module_id'], 'theme.')) return app(NativeThemeDeployment::class)->lifecycle($actor, $input, $key);
        $id = $input['module_id'];
        $package = $this->managed($id, $input['fingerprint']);
        $action = $input['action'] === 'upgrade' ? 'update' : $input['action'];
        if (!in_array($action, ['install', 'enable', 'disable', 'update', 'uninstall'], true)) throw new ExtensionFailure('MODULE_DEPLOYMENT_INPUT_INVALID', 422);
        if ($action === 'uninstall' && ($package['required'] || $this->plugins->isCorePlugin($id))) throw new ExtensionFailure('MODULE_PROTECTED', 409);
        $authorize = static fn (array $installation) => NativeModuleActor::assertLocked($actor, ['platform.modules.read', 'platform.modules.manage']);
        $result = $this->deployments->execute($id, $action, NativeModuleActor::deploymentId($actor, $id, $action, $key), expected:
            $this->expected($input), authorize: $authorize);
        return ['module_id' => $id, 'phase' => $result['phase'], 'action' => $input['action']];
    }

    public function managed(string $id, ?string $fingerprint = null): array
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $id) !== 1) throw new ExtensionFailure('MODULE_NOT_AVAILABLE', 404);
        $package = $this->runtime->package($id, $fingerprint);
        if (($package['manifest']['lifecycle'] ?? 'managed') !== 'managed') throw new ExtensionFailure('MODULE_LIFECYCLE_OWNED_BY_PAYMENT', 409);
        return $package;
    }

    private function expected(array $input): array
    {
        return ['fingerprint' => $input['fingerprint'], 'revision' => $input['expected_revision'],
            'input_hash' => hash('sha256', ProviderCodec::canonical($input))];
    }

    private function configurationPermissions(array $package): array
    {
        $permissions = ['platform.modules.read', 'platform.modules.configure'];
        $view = $package['manifest']['presentation'] ?? null;
        if (($view['actor'] ?? null) === 'admin' && isset($view['configuration_permission'])) $permissions[] = $view['configuration_permission'];
        return $permissions;
    }

    private function privateFields(array $package): array
    {
        return array_values(array_unique(array_merge($package['manifest']['secrets'] ?? [], array_filter(
            array_keys($package['manifest']['config_schema']['properties'] ?? []), ModulePublicProjection::sensitive(...)))));
    }

    /** The deployment owner merges top-level credential slots; nested private values need their own owner. */
    private function editableSchema(array $schema, array $privateFields): bool
    {
        foreach ($schema['properties'] ?? [] as $name => $property) {
            if (in_array($name, $privateFields, true)) continue;
            if ($this->hasPrivateSchema($property)) return false;
        }
        return true;
    }

    private function hasPrivateSchema(array $schema): bool
    {
        foreach ($schema['properties'] ?? [] as $name => $property) {
            if (ModulePublicProjection::sensitive($name) || $this->hasPrivateSchema($property)) return true;
        }
        if (isset($schema['items']) && $this->hasPrivateSchema($schema['items'])) return true;
        foreach ($schema['anyOf'] ?? [] as $alternative) if ($this->hasPrivateSchema($alternative)) return true;
        return false;
    }

    private function card(ModuleDescriptor $module, User $actor): array
    {
        $descriptor = $module->toArray();
        $id = $module->manifest->id;
        $subtype = null;
        $package = null;
        $row = null;
        $protected = $descriptor['source'] === 'system';
        $configuration = $presentation = false;
        $themeFingerprint = null;
        $actions = [];
        if ($descriptor['type'] === 'plugin' && preg_match('/\A[a-z0-9_]+\z/D', $id) === 1) {
            $directory = $this->plugins->resolvePluginPath($id);
            if ($directory !== null && is_file($directory.'/config.json') && !is_link($directory.'/config.json') && filesize($directory.'/config.json') <= 65536) {
                $metadata = json_decode(file_get_contents($directory.'/config.json'), true);
                if (in_array($metadata['type'] ?? null, ['feature', 'payment'], true)) $subtype = $metadata['type'];
            }
            $protected = $this->plugins->isCorePlugin($id) || (bool) ($this->packages->package($id)['required'] ?? false);
            try { $package = $this->managed($id); } catch (ExtensionFailure) {}
            $row = Plugin::query()->useWritePdo()->where('code', $id)->sharedLock()->first();
            if ($package !== null) {
                $configuration = $row !== null && ($package['manifest']['config_schema']['properties'] ?? []) !== []
                    && $this->editableSchema($package['manifest']['config_schema'], $this->privateFields($package));
                foreach ($this->configurationPermissions($package) as $permission) $configuration = $configuration && NativeModuleActor::permitted($actor, $permission);
                $view = $package['manifest']['presentation'] ?? null;
                $presentation = $row !== null && $row->is_enabled && $row->native_payment_mutation_token === null
                    && ($view['actor'] ?? null) === 'admin' && NativeModuleActor::permitted($actor, $view['permission']);
                if ($row?->native_payment_mutation_token === null && NativeModuleActor::permitted($actor, 'platform.modules.manage')) {
                    if ($row === null) $actions[] = 'install';
                    elseif ($row->is_enabled) $actions[] = 'disable';
                    else {
                        if ($row->version === $package['version'] && (int) $row->module_schema_version === $package['manifest']['schema_version']) $actions[] = 'enable';
                        if ($this->upgradeAvailable($package, $row)) $actions[] = 'upgrade';
                        if (!$protected) $actions[] = 'uninstall';
                    }
                }
            }
        }
        if ($descriptor['type'] === 'theme') {
            $theme = app(NativeThemeConfiguration::class)->card($id);
            $deployment = app(NativeThemeDeployment::class)->card($actor, $id);
            $themeFingerprint = $deployment['fingerprint'];
            $actions = $deployment['actions'];
            $configuration = $theme['configuration_available'] && NativeModuleActor::permitted($actor, 'platform.modules.configure');
        }
        return ['id' => $id, 'name' => $descriptor['name'], 'version' => $descriptor['version'], 'type' => $descriptor['type'],
            'subtype' => $subtype, 'source' => $descriptor['source'], 'description' => $descriptor['description'] ?? '', 'author' => $descriptor['author'] ?? '',
            'installed' => $row === null ? $descriptor['installed'] : true, 'enabled' => $row === null ? $descriptor['enabled'] : (bool) $row->is_enabled,
            'active' => $descriptor['active'], 'health' => $descriptor['health'], 'managed' => $package !== null, 'protected' => $protected,
            'upgrade_available' => $package !== null && $row !== null && $this->upgradeAvailable($package, $row),
            'configuration_available' => $configuration, 'presentation_available' => $presentation,
            'revision' => $descriptor['type'] === 'theme' ? $deployment['revision'] : ($package === null ? null : ModuleDeploymentRevision::of($package, $row)),
            'fingerprint' => $package['fingerprint'] ?? $themeFingerprint, 'actions' => $actions];
    }

    private function upgradeAvailable(array $package, Plugin $row): bool
    {
        return version_compare($package['version'], $row->version, '>') || $package['version'] === $row->version
            && $package['manifest']['schema_version'] > (int) $row->module_schema_version;
    }

    private function json(mixed $value, int $limit): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > $limit) throw new ExtensionFailure('MODULE_DISPLAY_LIMIT', 422);
        return $json;
    }
}
