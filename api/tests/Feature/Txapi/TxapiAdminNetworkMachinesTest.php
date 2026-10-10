<?php

namespace Tests\Feature\Txapi;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TxapiAdminNetworkMachinesTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/txapi/admin/native_machine_secret/network-machines';

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['secure_path' => 'native_machine_secret']);
        admin_setting(['app_url' => 'https://panel.example.test']);
    }

    public function test_native_machine_admin_respects_auth_and_rotating_path(): void
    {
        $this->getJson(self::ROOT)->assertStatus(403);
        $this->postJson(self::ROOT, ['name' => 'Unauthorized'])->assertStatus(403);
        Sanctum::actingAs($this->user('ordinary-machine@example.test'));
        $this->getJson(self::ROOT)->assertStatus(403);
        $this->postJson(self::ROOT . '/1/credentials')->assertStatus(403);
        Sanctum::actingAs($this->user('root-machine@example.test', true));
        $this->getJson('/txapi/admin/wrong/network-machines')->assertStatus(404);
        $this->getJson(self::ROOT)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::ROOT . '?per_page=101')->assertStatus(422);
    }

    public function test_machine_create_update_credentials_and_rotation(): void
    {
        Sanctum::actingAs($this->user('machine-editor@example.test', true));
        $this->postJson(self::ROOT, ['name' => '  '])->assertStatus(422);
        $response = $this->postJson(self::ROOT, [
            'name' => ' Tokyo-Machine ', 'notes' => 'test', 'is_active' => true, 'image_channel' => 'dev',
        ])->assertStatus(201);
        $id = $response->json('data.id');
        $oldToken = (string) $response->json('data.token');
        $this->assertSame(32, strlen($oldToken));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("install --mode machine", $response->json('data.install_command'));
        $this->assertStringContainsString("--channel 'dev'", $response->json('data.install_command'));
        $this->assertDatabaseHas('tx_server_machine', ['id' => $id, 'name' => 'Tokyo-Machine']);

        $this->getJson(self::ROOT)->assertOk()
            ->assertJsonPath('data.0.name', 'Tokyo-Machine')
            ->assertJsonPath('data.0.image_channel', 'dev')
            ->assertJsonMissingPath('data.0.token')
            ->assertJsonMissingPath('data.0.install_command');

        $this->putJson(self::ROOT . '/' . $id,
            ['name' => 'Tokyo-Renamed', 'is_active' => false, 'image_channel' => 'stable'])->assertOk();
        $this->assertFalse(ServerMachine::findOrFail($id)->is_active);
        $this->assertSame('stable', ServerMachine::findOrFail($id)->image_channel);

        $credentials = $this->postJson(self::ROOT . '/' . $id . '/credentials')
            ->assertOk()->json('data');
        $this->assertSame($oldToken, $credentials['token']);
        $this->assertStringContainsString("--machine-id {$id}", $credentials['install_command']);
        $this->assertStringContainsString("--channel 'stable'", $credentials['install_command']);
        $rotated = $this->postJson(self::ROOT . '/' . $id . '/token/rotate')
            ->assertOk();
        $this->assertNotSame($oldToken, $rotated->json('data.token'));
        $this->assertSame($rotated->json('data.token'), ServerMachine::findOrFail($id)->token);
        $this->assertStringContainsString('no-store', (string) $rotated->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($oldToken, $rotated->getContent());
        $this->assertDatabaseHas('tx_admin_audit_log', ['method' => 'POST']);
    }

    public function test_machine_delete_detaches_nodes_and_history_is_bounded(): void
    {
        Sanctum::actingAs($this->user('machine-delete@example.test', true));
        $machine = ServerMachine::create([
            'name' => 'TestMachine', 'token' => ServerMachine::generateToken(),
            'is_active' => true,
        ]);
        $node = Server::create([
            'type' => Server::TYPE_SOCKS, 'name' => 'Machine node',
            'host' => 'node.example.test', 'port' => '1080', 'server_port' => 1080,
            'rate' => 1, 'group_ids' => [], 'route_ids' => [],
            'protocol_settings' => [], 'machine_id' => $machine->id,
            'enabled' => true, 'show' => true,
        ]);
        $this->getJson(self::ROOT . '/' . $machine->id . '/nodes')
            ->assertOk()->assertJsonPath('data.0.id', $node->id)
            ->assertJsonPath('meta.total', 1);
        $this->getJson(self::ROOT . '/' . $machine->id . '/history?limit=9')
            ->assertStatus(422);
        $this->getJson(self::ROOT . '/' . $machine->id . '/history?limit=20&range_hours=24')
            ->assertOk()->assertJsonPath('data', []);
        $this->deleteJson(self::ROOT . '/' . $machine->id)
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertNull($node->fresh()->machine_id);
        $this->assertDatabaseMissing('tx_server_machine', ['id' => $machine->id]);
        $this->deleteJson(self::ROOT . '/' . $machine->id)->assertStatus(404);
        $this->postJson(self::ROOT . '/' . $machine->id . '/credentials')->assertStatus(404);
    }

    public function test_runtime_endpoint_rejects_unknown_target_and_old_tx_admin_routes_are_gone(): void
    {
        Sanctum::actingAs($this->user('machine-run@example.test', true));
        $machine = ServerMachine::create([
            'name' => 'Runtime', 'token' => ServerMachine::generateToken(),
            'is_active' => true,
        ]);
        $this->postJson(self::ROOT . '/' . $machine->id . '/runtime/update',
            ['target' => 'ghcr.io/untrusted:latest'])->assertStatus(422);
        $this->postJson(self::ROOT . '/' . $machine->id . '/runtime/update',
            ['target' => 'latest'])->assertStatus(422)
            ->assertJsonPath('error.code', 'MACHINE_RUNTIME_UNAVAILABLE');

        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route) => $route->uri())->all();
        foreach (['fetch', 'save', 'drop', 'resetToken', 'getToken',
            'installCommand', 'nodes', 'history', 'runtime/update'] as $tail) {
            $this->assertNotContains('api/v2/{admin_path}/server/machine/' . $tail, $uris);
        }
    }

    private function user(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email,
            'password' => 'test-password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => $admin,
            'banned' => 0,
        ]);
    }
}
