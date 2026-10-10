<?php

namespace Tests\Feature\Server;

use App\Models\ServerMachine;
use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class MachineRuntimeUpdateTest extends TestCase
{
    use RefreshDatabase;

    private string $securePath;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Sanctum::actingAs(User::create([
            'email' => 'machine-update-admin@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000151',
            'token' => str_repeat('b', 32),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 1,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]));

        $this->securePath = (string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );
    }

    public function test_machine_status_accepts_and_bounds_runtime_update_state(): void
    {
        $machine = $this->machine([
            'token' => 'runtime-machine-token',
        ]);

        $response = $this->postJson('/txapi/node/v1/machine/status', [
            'protocol_version' => 1,
            'machine_id' => $machine->id,
            'cpu' => 12.5,
            'mem' => ['total' => 1024, 'used' => 512],
            'swap' => ['total' => 128, 'used' => 1],
            'disk' => ['total' => 4096, 'used' => 1024],
            'runtime' => [
                'version' => 'v2.3.0',
                'build_time' => '2026-09-23T04:00:00Z',
                'deployment' => 'docker',
                'updater_available' => true,
                'update' => [
                    'request_id' => 'mup_test-01',
                    'target' => 'latest',
                    'status' => 'succeeded',
                    'updated_at' => 1780000000,
                    'message' => 'upgrade completed',
                ],
            ],
        ], ['Authorization' => 'Bearer runtime-machine-token',
            'X-TX-Machine-ID' => (string) $machine->id]);

        $response->assertOk();
        $machine->refresh();

        $runtime = $machine->load_status['runtime'] ?? null;
        $this->assertIsArray($runtime);
        $this->assertSame('v2.3.0', $runtime['version']);
        $this->assertTrue($runtime['updater_available']);
        $this->assertSame('succeeded', $runtime['update']['status']);
        $this->assertSame('latest', $runtime['update']['target']);
    }

    public function test_machine_status_redacts_secret_like_update_message(): void
    {
        $machine = $this->machine(['token' => 'redaction-machine-token']);

        $this->postJson('/txapi/node/v1/machine/status', [
            'protocol_version' => 1,
            'machine_id' => $machine->id,
            'cpu' => 1,
            'mem' => ['total' => 1024, 'used' => 128],
            'runtime' => [
                'version' => 'v2.3.0',
                'deployment' => 'docker',
                'updater_available' => true,
                'update' => [
                    'request_id' => 'mup_test-02',
                    'target' => 'latest',
                    'status' => 'failed',
                    'updated_at' => 1780000001,
                    'message' => 'registry token=super-secret',
                ],
            ],
        ], ['Authorization' => 'Bearer redaction-machine-token',
            'X-TX-Machine-ID' => (string) $machine->id])->assertOk();

        $machine->refresh();
        $this->assertSame(
            '[REDACTED]',
            $machine->load_status['runtime']['update']['message'] ?? null
        );
    }

    public function test_admin_can_dispatch_latest_update_only_to_online_capable_machine(): void
    {
        $machine = $this->machine([
            'last_seen_at' => now()->timestamp,
            'load_status' => [
                'runtime' => [
                    'version' => 'v2.3.0',
                    'deployment' => 'docker',
                    'updater_available' => true,
                ],
            ],
        ]);
        NodeSyncService::markMachineOnline($machine->id);

        Redis::shouldReceive('publish')
            ->once()
            ->with('node:push', Mockery::on(function ($payload) use ($machine) {
                $decoded = json_decode($payload, true);
                return ($decoded['machine_id'] ?? null) === $machine->id
                    && ($decoded['event'] ?? null) === 'ops.machine.runtime.update'
                    && ($decoded['data']['target'] ?? null) === 'latest'
                    && str_starts_with((string) ($decoded['data']['request_id'] ?? ''), 'mup_');
            }))
            ->andReturn(1);

        $response = $this->postJson(
            "/txapi/admin/{$this->securePath}/network-machines/{$machine->id}/runtime/update",
            ['machine_id' => $machine->id, 'target' => 'latest']
        );

        $response->assertOk()
            ->assertJsonPath('data.machine_id', $machine->id)
            ->assertJsonPath('data.target', 'latest')
            ->assertJsonPath('data.status', 'accepted');

        $this->assertStringStartsWith('mup_', (string) $response->json('data.request_id'));

        $this->assertDatabaseHas('v2_admin_audit_log', [
            'method' => 'POST',
        ]);
    }

    public function test_admin_update_rejects_arbitrary_target(): void
    {
        $machine = $this->machine();

        $this->postJson(
            "/txapi/admin/{$this->securePath}/network-machines/{$machine->id}/runtime/update",
            ['machine_id' => $machine->id, 'target' => 'ghcr.io/example/other:latest']
        )->assertStatus(422);
    }

    public function test_admin_update_rejects_machine_without_installer_bridge(): void
    {
        $machine = $this->machine([
            'last_seen_at' => now()->timestamp,
            'load_status' => [
                'runtime' => [
                    'version' => 'v2.2.3',
                    'deployment' => 'unknown',
                    'updater_available' => false,
                ],
            ],
        ]);
        NodeSyncService::markMachineOnline($machine->id);

        $this->postJson(
            "/txapi/admin/{$this->securePath}/network-machines/{$machine->id}/runtime/update",
            ['machine_id' => $machine->id, 'target' => 'latest']
        )
            ->assertStatus(422)
            ->assertJsonPath('error.message', 'Machine runtime updater is unavailable; update TX-Node Installer first');
    }

    public function test_admin_update_rejects_stale_or_disconnected_machine(): void
    {
        $machine = $this->machine([
            'last_seen_at' => now()->subMinutes(10)->timestamp,
            'load_status' => [
                'runtime' => [
                    'updater_available' => true,
                    'deployment' => 'docker',
                ],
            ],
        ]);
        NodeSyncService::markMachineOnline($machine->id);

        $this->postJson(
            "/txapi/admin/{$this->securePath}/network-machines/{$machine->id}/runtime/update",
            ['machine_id' => $machine->id, 'target' => 'latest']
        )
            ->assertStatus(422)
            ->assertJsonPath('error.message', 'Machine heartbeat is stale or offline');
    }

    private function machine(array $overrides = []): ServerMachine
    {
        return ServerMachine::create(array_merge([
            'name' => 'Tokyo-Update',
            'token' => 'machine-token-' . bin2hex(random_bytes(6)),
            'is_active' => true,
            'last_seen_at' => now()->timestamp,
            'load_status' => [],
        ], $overrides));
    }
}
