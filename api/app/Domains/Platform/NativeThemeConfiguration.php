<?php

namespace App\Domains\Platform;

use App\Core\Host\Contracts\TransportSchema;
use App\Domains\CredentialProvider\ProviderCodec;
use App\Domains\Installation\Contracts\InstallationAdmission;
use App\Domains\InternalApi\StrictJson;
use App\Extensions\Contracts\ExtensionFailure;
use App\Models\Setting;
use App\Models\User;
use App\Services\Module\ModuleId;
use App\Services\NativeSettingsStorage;
use App\Services\ThemeService;
use Illuminate\Support\Facades\DB;

/** ThemeService remains the theme configuration owner; this adds bounded public projection, CAS and atomic receipts. */
final class NativeThemeConfiguration
{
    public function __construct(private readonly ThemeService $themes, private readonly InstallationAdmission $installation) {}

    public function configuration(User $actor, string $id): array
    {
        $name = $this->themeName($id);
        return $this->themes->withConfigurationLock($name, fn () => DB::transaction(function () use ($actor, $id, $name): array {
            $state = $this->installation->assertNativeRead();
            NativeModuleActor::assertLocked($actor, ['platform.modules.read', 'platform.modules.configure']);
            return $this->snapshot($id, $name, $state['installation_id']);
        }));
    }

    public function save(User $actor, array $input, string $key): array
    {
        if ($input['credential_references'] !== []) throw new ExtensionFailure('MODULE_CONFIGURATION_INVALID', 422);
        $id = $input['module_id'];
        $name = $this->themeName($id);
        return $this->themes->withConfigurationLock($name, fn () => DB::transaction(function () use ($actor, $input, $key, $id, $name): array {
            $state = $this->installation->assertNativeMutation();
            NativeModuleActor::assertLocked($actor, ['platform.modules.read', 'platform.modules.configure']);
            app(NativeSettingsStorage::class)->lock();
            $keyHash = hash('sha256', 'native-theme-configuration/v1:'.$state['installation_id'].':'.$actor->id.':'.$id.':'.$key);
            $inputHash = hash('sha256', ProviderCodec::canonical($input));
            $receipt = DB::table('tx_native_theme_admin_request')->where('key_hash', $keyHash)->lockForUpdate()->first();
            if ($receipt !== null) {
                if (!hash_equals($receipt->input_hash, $inputHash) || $receipt->installation_id !== $state['installation_id']
                    || (string) $receipt->actor_id !== (string) $actor->id || $receipt->module_id !== $id) throw new ExtensionFailure('MODULE_DEPLOYMENT_CONFLICT');
                return ['module_id' => $id, 'phase' => 'completed', 'action' => 'configure'];
            }
            $snapshot = $this->snapshot($id, $name, $state['installation_id']);
            if (!hash_equals($snapshot['fingerprint'], $input['fingerprint']) || !hash_equals($snapshot['revision'], $input['expected_revision'])) {
                throw new ExtensionFailure('MODULE_STATE_CHANGED');
            }
            $configuration = StrictJson::object($input['configuration_json']);
            $wire = json_decode($input['configuration_json'], false, 32, JSON_THROW_ON_ERROR);
            $schema = json_decode($snapshot['schema_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!(new TransportSchema)->acceptsWire($wire, $schema)) throw new ExtensionFailure('MODULE_CONFIGURATION_INVALID', 422);
            $this->themes->updateConfig($name, $configuration);
            DB::table('tx_native_theme_admin_request')->insert(['key_hash' => $keyHash, 'installation_id' => $state['installation_id'],
                'actor_id' => $actor->id, 'module_id' => $id, 'input_hash' => $inputHash, 'created_at' => time()]);
            return ['module_id' => $id, 'phase' => 'completed', 'action' => 'configure'];
        }));
    }

    public function card(string $id): array
    {
        $name = $this->themeName($id);
        $snapshot = $this->snapshot($id, $name, 'card');
        return ['fingerprint' => $snapshot['fingerprint'], 'configuration_available' => json_decode($snapshot['schema_json'], true)['properties'] !== []];
    }

    private function themeName(string $id): string
    {
        foreach ($this->themes->getList() as $name => $metadata) if (ModuleId::theme((string) $name) === $id) return (string) $name;
        throw new ExtensionFailure('MODULE_NOT_FOUND', 404);
    }

    private function snapshot(string $id, string $name, string $installationId): array
    {
        $metadata = $this->themes->getList()[$name] ?? throw new ExtensionFailure('MODULE_NOT_FOUND', 404);
        $publicKeys = array_keys($this->themes->getPublicConfig($name));
        $fields = $values = [];
        $row = Setting::query()->useWritePdo()->where('name', 'theme_'.$name)->sharedLock()->first();
        $saved = is_array($row?->value) ? $row->value : [];
        foreach ($metadata['configs'] ?? [] as $field) {
            $key = $field['field_name'];
            if (!in_array($key, $publicKeys, true) || ModulePublicProjection::sensitive($key)) continue;
            $default = $field['default_value'] ?? '';
            $definition = match (true) {
                is_string($default) => ['type' => 'string', 'maxLength' => $key === 'custom_html' ? 16384 : 4096],
                is_bool($default) => ['type' => 'boolean'],
                is_int($default) && abs($default) <= 9007199254740991 => ['type' => 'integer', 'minimum' => -9007199254740991, 'maximum' => 9007199254740991],
                default => null,
            };
            if ($definition === null) continue;
            if (($field['field_type'] ?? null) === 'select' && is_array($field['select_options'] ?? null)) $definition['enum'] = array_keys($field['select_options']);
            if ($key === 'background_url') $definition['pattern'] = '^(?:$|https?://[^ ]+)$';
            $fields[$key] = $definition;
            $values[$key] = $saved[$key] ?? $default;
        }
        if (count($fields) > 64) throw new ExtensionFailure('MODULE_CONFIGURATION_INVALID', 422);
        $schema = ['type' => 'object', 'properties' => $fields, 'required' => array_keys($fields), 'additionalProperties' => false];
        $fingerprint = hash('sha256', ProviderCodec::canonical([$name, $metadata, $schema]));
        $revision = hash_hmac('sha256', ProviderCodec::canonical([$installationId, $id, $fingerprint, $saved]), (string) config('app.key'));
        $configuration = json_encode((object) $values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $definition = json_encode(TransportSchema::wireDefinition($schema), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($configuration) > 32768 || strlen($definition) > 32768) throw new ExtensionFailure('MODULE_DISPLAY_LIMIT', 422);
        return ['module_id' => $id, 'fingerprint' => $fingerprint, 'revision' => $revision, 'configuration_json' => $configuration,
            'schema_json' => $definition, 'credentials' => []];
    }
}
