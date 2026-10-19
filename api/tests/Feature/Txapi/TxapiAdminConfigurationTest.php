<?php

namespace Tests\Feature\Txapi;

use App\Domains\InternalApi\ServiceCredentials;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\Setting;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NativeRuntimeTestCase;

final class TxapiAdminConfigurationTest extends NativeRuntimeTestCase
{
    public function test_direct_network_reads_do_not_require_list_scans_and_never_project_node_or_machine_secrets(): void
    {
        [$invoke, $actor] = $this->managementClient(['network.nodes.get', 'network.machines.get'],
            ['network.nodes.read', 'network.machines.read']);
        $nodeSecret = 'node-protocol-secret-must-not-escape';
        $certificateSecret = 'certificate-private-key-must-not-escape';
        DB::table('tx_server')->where('id', $this->node->id)->update([
            'protocol_settings' => json_encode(['network' => 'tcp', 'tls' => 0, 'password' => $nodeSecret], JSON_THROW_ON_ERROR),
            'cert_config' => json_encode(['private_key' => $certificateSecret], JSON_THROW_ON_ERROR),
        ]);
        $machine = ServerMachine::create(['name' => 'Private machine', 'token' => ServerMachine::generateToken(), 'is_active' => true]);
        $node = $invoke('network.nodes.get', ['node_id' => (string) $this->node->id])->assertOk()
            ->assertJsonPath('data.id', (string) $this->node->id)
            ->assertJsonMissingPath('data.protocol_settings')->assertJsonMissingPath('data.cert_config');
        $this->assertStringNotContainsString($nodeSecret, $node->getContent());
        $this->assertStringNotContainsString($certificateSecret, $node->getContent());
        $machineResponse = $invoke('network.machines.get', ['machine_id' => (string) $machine->id])->assertOk()
            ->assertJsonPath('data.id', (string) $machine->id)->assertJsonPath('data.name', 'Private machine')
            ->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.install_command');
        $this->assertStringNotContainsString($machine->token, $machineResponse->getContent());
        $invoke('network.nodes.get', ['node_id' => '9223372036854775807'])->assertNotFound();
        $invoke('network.machines.get', ['machine_id' => '9223372036854775807'])->assertNotFound();
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->delete();
        $invoke('network.nodes.get', ['node_id' => (string) $this->node->id])->assertForbidden();
        $invoke('network.machines.get', ['machine_id' => (string) $machine->id])->assertForbidden();
        $this->assertNotNull(Server::find($this->node->id));
    }

    public function test_configuration_reads_native_settings_and_dirty_patch_preserves_omitted_credentials_and_original_key_replay(): void
    {
        [$invoke, $actor] = $this->configurationClient();
        Setting::createOrUpdate('app_name', 'Native original');
        Setting::createOrUpdate('app_description', 'Preserve this description');
        Setting::createOrUpdate('email_password', 'smtp-secret-preserved');
        Setting::createOrUpdate('telegram_bot_token', 'telegram-secret-preserved');
        Setting::createOrUpdate('password_limit_count', 7);
        // A stale helper cache must not override the write-primary settings snapshot.
        admin_setting([]);
        Setting::createOrUpdate('app_name', 'Native authoritative');
        $snapshot = $invoke('settings.configuration.get', [])->assertOk()
            ->assertJsonPath('data.values.app_name', 'Native authoritative')
            ->assertJsonPath('data.values.login_limit_count', 7)
            ->assertJsonMissingPath('data.values.email_password')->assertJsonMissingPath('data.values.telegram_bot_token');
        $this->assertStringNotContainsString('smtp-secret-preserved', $snapshot->getContent());
        $this->assertStringNotContainsString('telegram-secret-preserved', $snapshot->getContent());
        $credentials = collect($snapshot->json('data.credentials'))->keyBy('name');
        $this->assertTrue($credentials['email_password']['configured']);
        $this->assertTrue($credentials['telegram_bot_token']['configured']);
        $version = $snapshot->json('data.version');
        $key = (string) Str::uuid();
        $input = ['expected_version' => $version, 'values' => ['app_name' => 'First update', 'login_limit_count' => 9]];
        $invoke('settings.configuration.update', $input, $key)->assertOk();
        $this->assertSame('First update', Setting::where('name', 'app_name')->firstOrFail()->value);
        $this->assertSame('Preserve this description', Setting::where('name', 'app_description')->firstOrFail()->value);
        $this->assertSame('smtp-secret-preserved', Setting::where('name', 'email_password')->firstOrFail()->value);
        $this->assertSame('telegram-secret-preserved', Setting::where('name', 'telegram_bot_token')->firstOrFail()->value);
        $this->assertSame(9, (int) Setting::where('name', 'password_limit_count')->firstOrFail()->value);
        $updated = $invoke('settings.configuration.get', [])->assertOk()->json('data.version');
        $this->assertNotSame($version, $updated);
        $invoke('settings.configuration.update', ['expected_version' => $version, 'values' => ['app_name' => 'Stale overwrite']])->assertStatus(409);
        $invoke('settings.configuration.update', ['expected_version' => $updated, 'values' => ['app_name' => 'Later update']])->assertOk();
        // Restoring the original request after a lost response returns its receipt and must not overwrite a later edit.
        $invoke('settings.configuration.update', $input, $key)->assertOk();
        $this->assertSame('Later update', Setting::where('name', 'app_name')->firstOrFail()->value);
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->where('permission', 'settings.write')->delete();
        $invoke('settings.configuration.update', $input, $key)->assertForbidden();
        $invoke('settings.configuration.update', ['expected_version' => $updated, 'values' => ['app_name' => 'Unauthorized overwrite']])->assertForbidden();
        $this->assertSame('Later update', Setting::where('name', 'app_name')->firstOrFail()->value);
    }

    public function test_configuration_rejects_unknown_fields_and_corrupt_native_evidence_without_partial_writes(): void
    {
        [$invoke, $actor] = $this->configurationClient();
        Setting::createOrUpdate('app_name', 'Unchanged');
        $version = $invoke('settings.configuration.get', [])->assertOk()->json('data.version');
        $invoke('settings.configuration.update', ['expected_version' => $version,
            'values' => ['app_name' => 'Must roll back', 'server_token' => 'injected-credential']])->assertStatus(422);
        $invoke('settings.configuration.update', ['expected_version' => $version,
            'values' => ['app_name' => 'Must not save'], 'extra' => true])->assertStatus(422);
        $this->assertSame('Unchanged', Setting::where('name', 'app_name')->firstOrFail()->value);
        $invoke('settings.configuration.update', ['expected_version' => $version, 'values' => (object) []])->assertStatus(422);
        Setting::createOrUpdate('password_limit_count', -1);
        Setting::createOrUpdate('email_password', 'corrupt-path-secret');
        $response = $invoke('settings.configuration.get', [])->assertStatus(503)->assertJsonMissingPath('data.values');
        $this->assertStringNotContainsString('corrupt-path-secret', $response->getContent());
        $invoke('settings.configuration.update', ['expected_version' => $version, 'values' => ['app_name' => 'Corrupt overwrite']])->assertStatus(503);
        $this->assertSame('Unchanged', Setting::where('name', 'app_name')->firstOrFail()->value);
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->where('permission', 'settings.read')->delete();
        $invoke('settings.configuration.get', [])->assertForbidden();
    }

    public function test_compatibility_writer_invalidates_configuration_snapshot_and_original_receipt_cannot_overwrite_later_values(): void
    {
        [$invoke, $actor] = $this->configurationClient();
        Setting::createOrUpdate('app_name', 'Before compatibility edit');
        Setting::createOrUpdate('email_password', 'shared-storage-secret');
        $original = $invoke('settings.configuration.get', [])->assertOk()->json('data.version');
        $key = (string) Str::uuid();
        $input = ['app_name' => 'Compatibility edit'];
        $invoke('settings.site.update', $input, $key)->assertOk();
        $this->assertSame('Compatibility edit', Setting::where('name', 'app_name')->firstOrFail()->value);
        $invoke('settings.configuration.update', ['expected_version' => $original,
            'values' => ['app_name' => 'Stale snapshot overwrite']])->assertStatus(409);
        $snapshot = $invoke('settings.configuration.get', [])->assertOk()
            ->assertJsonPath('data.values.app_name', 'Compatibility edit');
        $current = $snapshot->json('data.version');
        $this->assertNotSame($original, $current);
        $invoke('settings.configuration.update', ['expected_version' => $current,
            'values' => ['app_name' => 'Current snapshot edit']])->assertOk();
        $invoke('settings.site.update', $input, $key)->assertOk();
        $this->assertSame('Current snapshot edit', Setting::where('name', 'app_name')->firstOrFail()->value);
        $this->assertSame('shared-storage-secret', Setting::where('name', 'email_password')->firstOrFail()->value);
        $invoke('settings.configuration.get', [])->assertOk()->assertJsonPath('data.values.app_name', 'Current snapshot edit');
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->where('permission', 'settings.write')->delete();
        $invoke('settings.site.update', $input, $key)->assertForbidden();
        $invoke('settings.site.update', ['app_name' => 'Unauthorized compatibility edit'])->assertForbidden();
        $this->assertSame('Current snapshot edit', Setting::where('name', 'app_name')->firstOrFail()->value);
    }

    private function configurationClient(): array
    {
        return $this->managementClient(['settings.configuration.get', 'settings.configuration.update', 'settings.site.update'],
            ['settings.read', 'settings.write']);
    }

    private function managementClient(array $operations, array $permissions): array
    {
        $this->activate();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        admin_setting(['secure_path' => 'configuration_admin']);
        [, $actor] = app(NativeIdentityService::class)->create('configuration-admin@example.test', 'admin-password');
        $actor->forceFill(['is_admin' => true])->saveOrFail();
        $bearer = 'Bearer '.$actor->createToken('configuration', ['*'])->plainTextToken;
        foreach ($permissions as $permission) {
            DB::table('tx_internal_actor_grant')->insert(['user_id' => $actor->id, 'permission' => $permission, 'created_at' => time()]);
        }
        $fingerprint = str_repeat('e', 64);
        $service = app(ServiceCredentials::class)->issue('configuration-admin', 'admin', $fingerprint, $operations, time() + 3600);
        $invoke = function (string $operation, array $input, ?string $key = null) use ($bearer, $service, $fingerprint) {
            return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
                ->postJson('https://core.example.test/txapi/internal/native/v1/admin/operations/'.$operation, [
                    'contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time() + 15,
                    'input' => $input === [] ? (object) [] : $input,
                ], ['Authorization' => $bearer, 'X-Core-Admin-Path' => 'configuration_admin',
                    'X-Core-Service-Id' => $service['service_id'], 'X-Core-Service-Credential' => $service['credential'],
                    'X-Core-Service-Epoch' => $service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
                    'X-Core-Tls-Certificate-Sha256' => $fingerprint, 'Idempotency-Key' => $key ?? (string) Str::uuid()]);
        };
        return [$invoke, $actor];
    }
}
