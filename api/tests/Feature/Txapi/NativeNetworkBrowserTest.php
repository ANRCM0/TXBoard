<?php

namespace Tests\Feature\Txapi;

use App\Models\ServerMachine;
use App\Domains\Network\NativeMachineObservations;
use App\Services\NodeSyncService;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NativeRuntimeTestCase;

final class NativeNetworkBrowserTest extends NativeRuntimeTestCase
{
    public function test_machine_observations_keep_exact_bytes_and_do_not_present_stale_load_as_zero(): void
    {
        $owner = new NativeMachineObservations();
        $machine = new ServerMachine();
        $machine->forceFill(['id' => 123, 'load_status' => ['updated_at' => 1000, 'cpu' => 81.25,
            'mem' => ['total' => '90071992547409930', 'used' => '9007199254740993'],
            'runtime' => ['message' => 'never-project-this-secret']]]);
        NodeSyncService::markMachineOnline(123);
        $fresh = $owner->project($machine, 1010);
        $this->assertTrue($fresh['online']);
        $this->assertTrue($fresh['high_load']);
        $this->assertSame('9007199254740993', $fresh['memory_used_bytes']);
        $this->assertSame('81.25', $fresh['cpu_percent']);
        $this->assertStringNotContainsString('never-project', json_encode($fresh));
        $stale = $owner->project($machine, 1181);
        $this->assertFalse($stale['telemetry_fresh']);
        $this->assertNull($stale['high_load']);
        $this->assertNull($stale['cpu_percent']);
        $this->assertNull($stale['memory_used_bytes']);
        $machine->load_status = ['updated_at' => 1200, 'cpu' => 99];
        $this->assertNull($owner->project($machine, 1100)['high_load']);
        NodeSyncService::markMachineOffline(123);
    }

    public function test_browser_network_receipts_never_retain_tokens_and_explicit_reads_recheck_authority(): void
    {
        $this->activate();
        config(['browser.enabled' => true, 'browser.admin_enabled' => true,
            'browser.origin' => 'https://core.example.test', 'browser.rate_store' => 'array']);
        admin_setting(['secure_path' => 'native_network']);
        [, $actor] = app(NativeIdentityService::class)->create('network-browser@example.test', 'admin-password');
        $actor->forceFill(['is_admin' => true])->saveOrFail();
        foreach (['network.machines.manage', 'network.machines.read', 'network.nodes.manage'] as $permission) {
            DB::table('tx_internal_actor_grant')->insert(['user_id' => $actor->id, 'permission' => $permission, 'created_at' => time()]);
        }
        $headers = ['Authorization' => 'Bearer '.$actor->createToken('browser', ['*'])->plainTextToken,
            'X-Core-Admin-Path' => 'native_network', 'Origin' => 'https://core.example.test'];
        $invoke = fn (string $operation, array $input, ?string $key = null) => $this->postJson(
            'https://core.example.test/gateway/v1/core/admin/operations/'.$operation,
            ['input' => $input === [] ? (object) [] : $input], $headers + ($key ? ['Idempotency-Key' => $key] : []));
        $key = (string) Str::uuid();
        $input = ['name' => 'Native browser server', 'notes' => null, 'is_active' => true, 'image_channel' => 'stable'];
        $created = $invoke('network.machines.create', $input, $key)->assertOk()->assertJsonMissingPath('data.token');
        $id = $created->json('data.id');
        $this->assertIsString($id);
        $overview = $invoke('network.machines.overview.get', [])->assertOk();
        $overview->assertJsonPath('data.freshness_seconds', 180)->assertJsonPath('data.high_load_threshold_percent', 80);
        $this->assertGreaterThanOrEqual(1, $overview->json('data.total'));
        $invoke('network.machines.history.get', ['machine_id' => $id, 'range_hours' => 1, 'limit' => 10])
            ->assertOk()->assertJsonPath('data.machine_id', $id)->assertJsonPath('data.points', []);
        $invoke('network.machines.create', $input, $key)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, ServerMachine::where('name', $input['name'])->count());
        $token = ServerMachine::findOrFail($id)->token;
        $credentials = $invoke('network.machines.credentials.get', ['machine_id' => $id]);
        $this->assertSame(200, $credentials->status(), (string) $credentials->json('error.code'));
        $credentials->assertJsonPath('data.token', $token)->assertHeader('Pragma', 'no-cache');
        $this->assertStringContainsString('no-store', $credentials->headers->get('Cache-Control'));
        $rotateKey = (string) Str::uuid();
        $invoke('network.machines.token.rotate', ['machine_id' => $id], $rotateKey)->assertOk()
            ->assertJsonPath('data.ok', true)->assertJsonMissingPath('data.token');
        $next = ServerMachine::findOrFail($id)->token;
        $this->assertNotSame($token, $next);
        $invoke('network.machines.token.rotate', ['machine_id' => $id], $rotateKey)->assertOk()->assertJsonMissingPath('data.token');
        $this->assertSame($next, ServerMachine::findOrFail($id)->token);
        $audit = json_encode(DB::table('tx_internal_audit')->get()->all());
        $this->assertStringNotContainsString($token, $audit);
        $this->assertStringNotContainsString($next, $audit);
        $snapshot = $invoke('network.nodes.configuration.get', ['node_id' => (string) $this->node->id])->assertOk();
        $this->assertStringContainsString('no-store', $snapshot->headers->get('Cache-Control'));
        $config = json_decode($snapshot->json('data.configuration_json'), true, flags: JSON_THROW_ON_ERROR);
        $config['cert_config'] = ['private_key' => 'browser-private-key'];
        $replace = ['node_id' => (string) $this->node->id, 'expected_revision' => $snapshot->json('data.revision'),
            'configuration_json' => json_encode($config, JSON_THROW_ON_ERROR)];
        $invoke('network.nodes.configuration.replace', $replace, (string) Str::uuid())->assertOk();
        $this->assertStringNotContainsString('browser-private-key', json_encode(DB::table('tx_internal_audit')->get()->all()));
        DB::table('tx_internal_actor_grant')->where('user_id', $actor->id)->delete();
        $invoke('network.machines.credentials.get', ['machine_id' => $id])->assertForbidden();
        $invoke('network.machines.overview.get', [])->assertForbidden();
        $invoke('network.machines.token.rotate', ['machine_id' => $id], $rotateKey)->assertForbidden();
        $invoke('network.nodes.configuration.get', ['node_id' => (string) $this->node->id])->assertForbidden();
    }
}
