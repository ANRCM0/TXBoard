<?php

namespace Tests\Feature\Txapi;

use App\Domains\InternalApi\ServiceCredentials;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NativeRuntimeTestCase;

final class NativeNodeConfigurationTest extends NativeRuntimeTestCase
{
    public function test_complete_configuration_round_trip_conflict_and_original_key_replay(): void
    {
        [$invoke, $actor] = $this->client();
        $snapshot = $invoke('network.nodes.configuration.get', ['node_id' => (string) $this->node->id])->assertOk();
        $configuration = json_decode($snapshot->json('data.configuration_json'), true, flags: JSON_THROW_ON_ERROR);
        $configuration['name'] = 'Native configured node';
        $configuration['transfer_enable'] = '9007199254740999';
        $configuration['cert_config'] = ['private_key' => 'retain-sensitive-configuration'];
        $input = ['node_id' => (string) $this->node->id, 'expected_revision' => $snapshot->json('data.revision'),
            'configuration_json' => json_encode($configuration, JSON_THROW_ON_ERROR)];
        $key = (string) Str::uuid();
        $invoke('network.nodes.configuration.replace', $input, $key)->assertOk()->assertJsonPath('data.id', (string) $this->node->id);
        $invoke('network.nodes.configuration.replace', $input, $key)->assertOk();
        $invoke('network.nodes.configuration.replace', $input)->assertStatus(409);
        $updated = $invoke('network.nodes.configuration.get', ['node_id' => (string) $this->node->id])->assertOk();
        $this->assertSame($configuration, json_decode($updated->json('data.configuration_json'), true, flags: JSON_THROW_ON_ERROR));
        $this->assertNotSame($snapshot->json('data.revision'), $updated->json('data.revision'));
        $invoke('network.nodes.get', ['node_id' => (string) $this->node->id])->assertOk()->assertJsonMissingPath('data.protocol_settings');
        $invoke('network.nodes.create', ['configuration_json' => json_encode($configuration + ['u' => '100'], JSON_THROW_ON_ERROR)])->assertStatus(422);
        $configuration['name'] = 'New configured node';
        $created = $invoke('network.nodes.create', ['configuration_json' => json_encode($configuration, JSON_THROW_ON_ERROR)])->assertOk()->json('data.id');
        $this->assertIsString($created);
        $this->assertSame('9007199254740999', (string) Server::findOrFail($created)->transfer_enable);
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->delete();
        $invoke('network.nodes.configuration.get', ['node_id' => (string) $this->node->id])->assertForbidden();
        $invoke('network.nodes.configuration.replace', $input, $key)->assertForbidden();
    }

    public function test_server_filter_precedes_pagination_and_protocol_metadata_is_encoded(): void
    {
        [$invoke] = $this->client();
        $machine = ServerMachine::create(['name' => 'Node parent machine', 'token' => ServerMachine::generateToken(), 'is_active' => true]);
        Server::withoutEvents(fn () => $this->node->forceFill(['machine_id' => $machine->id])->save());
        $invoke('network.nodes.list', ['machine_id' => (string) $machine->id, 'page' => 1, 'per_page' => 1])
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', (string) $this->node->id);
        $invoke('network.nodes.list', ['machine_id' => '9223372036854775807', 'page' => 1, 'per_page' => 1])
            ->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
        $metadata = $invoke('network.nodes.protocols.list', [])->assertOk()->json('data.metadata_json');
        $protocols = json_decode($metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(count(Server::VALID_TYPES), $protocols);
        $this->assertContains('vless', array_column($protocols, 'type'));
        $material = $invoke('network.nodes.material.generate', ['kind' => 'hex', 'bytes' => 8])->assertOk();
        $this->assertNotEmpty(json_decode($material->json('data.material_json'), true, flags: JSON_THROW_ON_ERROR));
    }

    private function client(): array
    {
        $this->activate();
        config(['internal_api.enabled' => true, 'internal_api.trusted_tls_proxies' => ['127.0.0.1']]);
        admin_setting(['secure_path' => 'node_configuration']);
        [, $actor] = app(NativeIdentityService::class)->create('node-config-admin@example.test', 'admin-password');
        $actor->forceFill(['is_admin' => true])->saveOrFail();
        $bearer = 'Bearer '.$actor->createToken('node-config', ['*'])->plainTextToken;
        foreach (['network.nodes.read', 'network.nodes.manage'] as $permission) {
            DB::table('tx_internal_actor_grant')->insert(['user_id' => $actor->id, 'permission' => $permission, 'created_at' => time()]);
        }
        $fingerprint = str_repeat('e', 64);
        $service = app(ServiceCredentials::class)->issue('node-config-admin', 'admin', $fingerprint,
            ['network.nodes.get', 'network.nodes.list', 'network.nodes.protocols.list', 'network.nodes.configuration.get',
                'network.nodes.configuration.replace', 'network.nodes.create', 'network.nodes.material.generate'], time() + 3600);
        $invoke = function (string $operation, array $input, ?string $key = null) use ($bearer, $service, $fingerprint) {
            return $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'])
                ->postJson('https://core.example.test/txapi/internal/native/v1/admin/operations/'.$operation, [
                    'contract_version' => '1', 'request_id' => (string) Str::uuid(), 'deadline_at' => time() + 15,
                    'input' => $input === [] ? (object) [] : $input,
                ], ['Authorization' => $bearer, 'X-Core-Admin-Path' => 'node_configuration',
                    'X-Core-Service-Id' => $service['service_id'], 'X-Core-Service-Credential' => $service['credential'],
                    'X-Core-Service-Epoch' => $service['epoch'], 'X-Core-Tls-Verified' => 'SUCCESS',
                    'X-Core-Tls-Certificate-Sha256' => $fingerprint, 'Idempotency-Key' => $key ?? (string) Str::uuid()]);
        };
        return [$invoke, $actor];
    }
}
