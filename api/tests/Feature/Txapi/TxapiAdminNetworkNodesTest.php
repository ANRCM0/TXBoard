<?php

namespace Tests\Feature\Txapi;

use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerRoute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TxapiAdminNetworkNodesTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/txapi/admin/network_nodes_secret/network-nodes';

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['secure_path' => 'network_nodes_secret']);
    }

    public function test_node_endpoints_enforce_admin_and_rotating_path(): void
    {
        $this->getJson(self::ROOT)->assertStatus(403);
        $this->postJson(self::ROOT, $this->payload())->assertStatus(403);
        Sanctum::actingAs($this->account('regular-node@example.test'));
        $this->getJson(self::ROOT)->assertStatus(403);
        $this->postJson(self::ROOT . '/secrets', ['kind' => 'hex'])->assertStatus(403);

        Sanctum::actingAs($this->account('admin-node@example.test', true));
        $this->getJson('/txapi/admin/incorrect/network-nodes')->assertStatus(404);
        $this->getJson(self::ROOT)->assertOk()
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.total', 0);
        $this->getJson(self::ROOT . '/protocols')->assertOk()
            ->assertJsonStructure(['data', 'request_id']);
        $this->postJson(self::ROOT . '/secrets', ['kind' => 'unsupported'])
            ->assertStatus(422);
    }

    public function test_node_create_list_replace_copy_and_guarded_delete(): void
    {
        Sanctum::actingAs($this->account('editor-node@example.test', true));
        $group = new ServerGroup();
        $group->name = 'Network';
        $group->save();
        $route = ServerRoute::create([
            'remarks' => 'direct', 'match' => ['example.com'],
            'action' => 'direct', 'enabled' => true, 'sort' => 10,
        ]);
        $payload = $this->payload([
            'group_ids' => [$group->id], 'route_ids' => [$route->id],
        ]);
        $this->postJson(self::ROOT, array_replace($payload, ['group_ids' => [999999]]))
            ->assertStatus(422);
        $created = $this->postJson(self::ROOT, $payload)
            ->assertOk()->assertJsonPath('data.ok', true);
        $id = $created->json('data.id');
        $this->assertDatabaseHas('tx_server', ['id' => $id, 'name' => 'Native Node']);

        $this->getJson(self::ROOT . '?page=1&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.groups.0.id', $group->id)
            ->assertJsonMissingPath('data.0.server_key');
        $this->getJson(self::ROOT . '?per_page=101')->assertStatus(422);

        $this->putJson(self::ROOT . '/' . $id, $this->payload([
            'name' => 'Renamed', 'group_ids' => [$group->id], 'route_ids' => [$route->id],
        ]))->assertOk();
        $this->assertSame('Renamed', Server::findOrFail($id)->name);
        $this->patchJson(self::ROOT . '/' . $id, ['show' => false])->assertOk();
        $this->assertFalse(Server::findOrFail($id)->show);

        $copy = $this->postJson(self::ROOT . '/' . $id . '/copy')
            ->assertOk()->json('data');
        $this->assertIsInt($copy);
        $this->assertNotSame($id, $copy);
        $this->assertFalse(Server::findOrFail($copy)->show);
        $this->assertNull(Server::findOrFail($copy)->code);

        $child = $this->postJson(self::ROOT, $this->payload(['parent_id' => $id]))
            ->assertOk()->json('data.id');
        $this->deleteJson(self::ROOT . '/' . $id)->assertStatus(409)
            ->assertJsonPath('error.code', 'NETWORK_NODE_HAS_CHILDREN');
        $this->putJson(self::ROOT . '/' . $id, $this->payload(['parent_id' => $child]))
            ->assertStatus(422);

        $this->deleteJson(self::ROOT . '/' . $child)->assertOk();
        $this->deleteJson(self::ROOT . '/' . $copy)->assertOk();
        $this->deleteJson(self::ROOT . '/' . $id)->assertOk();
        $this->deleteJson(self::ROOT . '/' . $id)->assertStatus(404);
    }

    public function test_batch_operations_reject_missing_and_duplicate_ids_without_partial_writes(): void
    {
        Sanctum::actingAs($this->account('bulk-node@example.test', true));
        $first = Server::create($this->payload(['name' => 'One']));
        $second = Server::create($this->payload(['name' => 'Two']));
        $first->u = 50;
        $first->d = 40;
        $first->save();

        $this->putJson(self::ROOT . '/sort', [
            ['id' => $first->id, 'order' => 4], ['id' => $first->id, 'order' => 9],
        ])->assertStatus(422);
        $this->putJson(self::ROOT . '/sort', [
            ['id' => $first->id, 'order' => 4], ['id' => 999999, 'order' => 1],
        ])->assertStatus(404);
        $this->assertNotSame(4, (int) $first->fresh()->sort);
        $this->putJson(self::ROOT . '/sort', [
            ['id' => $first->id, 'order' => 4], ['id' => $second->id, 'order' => 5],
        ])->assertOk();

        $this->patchJson(self::ROOT . '/batch', [
            'ids' => [$first->id, 999999], 'show' => false,
        ])->assertStatus(404);
        $this->assertTrue($first->fresh()->show);
        $this->patchJson(self::ROOT . '/batch', [
            'ids' => [$first->id, $second->id], 'enabled' => false,
        ])->assertOk();
        $this->assertFalse($first->fresh()->enabled);
        $this->assertFalse($second->fresh()->enabled);

        $this->postJson(self::ROOT . '/batch-traffic-reset', [
            'ids' => [$first->id, $second->id],
        ])->assertOk();
        $this->assertSame(0, (int) $first->fresh()->u);
        $this->assertSame(0, (int) $first->fresh()->d);

        $this->postJson(self::ROOT . '/batch-delete', [
            'ids' => [$first->id, 999999],
        ])->assertStatus(404);
        $this->assertDatabaseHas('tx_server', ['id' => $first->id]);
        $this->postJson(self::ROOT . '/batch-delete', [
            'ids' => [$first->id, $second->id],
        ])->assertOk();
        $this->assertDatabaseMissing('tx_server', ['id' => $first->id]);
    }

    public function test_native_secret_generation_has_bounded_input_and_no_store_response(): void
    {
        Sanctum::actingAs($this->account('secret-node@example.test', true));
        $x = $this->postJson(self::ROOT . '/secrets', ['kind' => 'x25519'])->assertOk();
        $this->assertNotEmpty($x->json('data.private_key'));
        $this->assertNotEmpty($x->json('data.public_key'));
        $this->assertStringContainsString('no-store', (string) $x->headers->get('Cache-Control'));
        $hex = $this->postJson(self::ROOT . '/secrets', ['kind' => 'hex', 'bytes' => 12])
            ->assertOk()->json('data.value');
        $this->assertSame(24, strlen($hex));
        $this->postJson(self::ROOT . '/secrets', ['kind' => 'hex', 'bytes' => 65])
            ->assertStatus(422);
        $this->postJson(self::ROOT . '/secrets', ['kind' => 'ech', 'public_name' => 'bad hostname'])
            ->assertStatus(422);
        $this->postJson(self::ROOT . '/secrets', ['kind' => 'ech', 'public_name' => 'ech.example.com'])
            ->assertOk()->assertJsonStructure(['data' => ['key', 'config']]);
    }

    public function test_no_legacy_node_admin_tx_routes_are_registered(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(
            static fn ($route) => $route->uri()
        )->all();
        foreach (['getNodes', 'protocols', 'generateSecret', 'generateEchKey', 'save',
            'update', 'copy', 'sort', 'drop', 'batchUpdate', 'batchDelete', 'resetTraffic',
            'batchResetTraffic'] as $path) {
            $this->assertNotContains('api/v2/{admin_path}/server/manage/' . $path, $uris);
        }
    }

    private function payload(array $extra = []): array
    {
        return array_replace([
            'type' => Server::TYPE_SOCKS, 'name' => 'Native Node',
            'host' => 'node.example.test', 'port' => '1080',
            'server_port' => 1080, 'rate' => 1,
            'group_ids' => [], 'route_ids' => [],
            'protocol_settings' => [], 'show' => true, 'enabled' => true,
        ], $extra);
    }

    private function account(string $email, bool $admin = false): User
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
