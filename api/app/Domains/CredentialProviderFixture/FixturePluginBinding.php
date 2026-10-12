<?php

namespace App\Domains\CredentialProviderFixture;

use App\Models\Plugin;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Immutable technical link for one trusted test plugin; never derives identity from its manifest. */
final class FixturePluginBinding
{
    public const CODE = 'credential_provider_fixture';
    public const TABLE = 'tx_credential_provider_shadow_plugin_binding';

    public function guard(): void
    {
        if (config('credential_provider_fixture.plugin_bridge_enabled') !== true
            || config('credential_provider_fixture.enabled') !== true || config('core_shadow.isolated_copy') !== true
            || !app()->environment('local', 'testing')) {
            throw new DomainException('PROVIDER_PLUGIN_CAPABILITY_UNAVAILABLE');
        }
        $columns = Schema::getColumnListing(self::TABLE); sort($columns);
        $expected = ['id', 'plugin_code', 'plugin_id', 'registration_id', 'instance_epoch', 'created_at']; sort($expected);
        if ($columns !== $expected || !Schema::hasIndex(self::TABLE, ['plugin_id'], 'unique')
            || !Schema::hasIndex(self::TABLE, ['plugin_code'], 'unique')
            || !Schema::hasIndex(self::TABLE, ['registration_id'], 'unique')) {
            throw new DomainException('PROVIDER_PLUGIN_SCHEMA_UNKNOWN');
        }
        $foreign = Schema::getForeignKeys(self::TABLE);
        foreach ([['plugin_id', 'tx_plugins', ['id']], ['registration_id', 'tx_credential_provider_shadow_registration', ['id', 'instance_epoch']]] as [$column, $table, $refs]) {
            $found = false;
            foreach ($foreign as $key) {
                if ($key['columns'] === ($column === 'plugin_id' ? [$column] : [$column, 'instance_epoch'])
                    && $key['foreign_table'] === $table && $key['foreign_columns'] === $refs
                    && in_array($key['on_delete'], ['restrict', 'no action'], true)
                    && in_array($key['on_update'], ['restrict', 'no action'], true)) { $found = true; }
            }
            if (!$found) { throw new DomainException('PROVIDER_PLUGIN_SCHEMA_UNKNOWN'); }
        }
        if (DB::getDriverName() === 'sqlite') {
            $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', self::TABLE)->value('sql');
            if (!is_string($sql) || !preg_match('/check\s*\(\s*["`]?plugin_code["`]?\s+in\s*\(\s*\x27credential_provider_fixture\x27\s*\)\s*\)/i', $sql)) {
                throw new DomainException('PROVIDER_PLUGIN_SCHEMA_UNKNOWN');
            }
        } else {
            $columns = array_column(Schema::getColumns(self::TABLE), null, 'name');
            if (($columns['plugin_code']['type'] ?? '') !== "enum('credential_provider_fixture')") { throw new DomainException('PROVIDER_PLUGIN_SCHEMA_UNKNOWN'); }
        }
    }

    public function bind(string $providerId): string
    {
        $this->guard(); FixtureCodec::provider($providerId);
        if (DB::transactionLevel() !== 0) { throw new DomainException('PROVIDER_OUTER_TRANSACTION_UNSUPPORTED'); }
        return DB::transaction(function () use ($providerId) {
            $plugin = Plugin::query()->where('code', self::CODE)->lockForUpdate()->first();
            if (!$plugin || !$plugin->is_enabled) { throw new DomainException('PROVIDER_PLUGIN_INSTALLATION_REQUIRED'); }
            $binding = DB::table(self::TABLE)->where('plugin_code', self::CODE)->lockForUpdate()->first();
            $registration = FixtureRegistration::lock($providerId, 'issue');
            if ($binding) {
                $this->identity($plugin, $binding, $registration);
                return (string) $binding->id;
            }
            // A missing association with any recorded activity is damaged history, never initial enrollment.
            if (DB::table('tx_credential_provider_shadow_operation')->where('registration_id', $registration->id)->exists()
                || DB::table('tx_credential_provider_shadow_lifecycle')->where('registration_id', $registration->id)->exists()) {
                throw new DomainException('PROVIDER_PLUGIN_BINDING_UNKNOWN');
            }
            FixtureLifecycleService::assertIssuable($registration, '0');
            $grants = json_decode($registration->operation_grants, true, flags: JSON_THROW_ON_ERROR);
            if (array_diff(['issue', 'query', 'revoke'], $grants)) { throw new DomainException('UNSUPPORTED_OPERATION'); }
            return (string) DB::table(self::TABLE)->insertGetId(['plugin_code' => self::CODE, 'plugin_id' => $plugin->id,
                'registration_id' => $registration->id, 'instance_epoch' => $registration->instance_epoch, 'created_at' => time()]);
        }, 5);
    }

    /** Called after the manager locked its plugin row, never from registration-first Provider paths. */
    public function locked(object $plugin, bool $runtimeCheck = false): array
    {
        $this->guard();
        $binding = DB::table(self::TABLE)->where('plugin_code', self::CODE)->lockForUpdate()->first();
        if (!$binding) { throw new DomainException('PROVIDER_PLUGIN_BINDING_UNKNOWN'); }
        // This lookup resolves an immutable ID, then takes the existing registration fence.
        $providerId = DB::table('tx_credential_provider_shadow_registration')->where('id', $binding->registration_id)->value('provider_id');
        if (!is_string($providerId)) { throw new DomainException('PROVIDER_PLUGIN_BINDING_UNKNOWN'); }
        $registration = FixtureRegistration::lock($providerId, $runtimeCheck ? 'issue' : null, $runtimeCheck);
        $this->identity($plugin, $binding, $registration);
        return [$binding, $registration];
    }

    private function identity(object $plugin, object $binding, object $registration): void
    {
        if ($plugin->code !== self::CODE || $binding->plugin_code !== self::CODE
            || (string) $binding->plugin_id !== (string) $plugin->id
            || (string) $binding->registration_id !== (string) $registration->id
            || (string) $binding->instance_epoch !== (string) $registration->instance_epoch) {
            throw new DomainException('PROVIDER_PLUGIN_BINDING_CONFLICT');
        }
    }
}
