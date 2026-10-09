<?php

namespace Tests\Feature\Txapi;

use App\Http\Controllers\Txapi\ServerController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TxapiUserNodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_nodes_require_user_and_return_empty_list_without_subscription(): void
    {
        $this->getJson('/txapi/me/nodes')->assertStatus(401);
        $user = User::create([
            'email' => 'nodes-no-plan@example.test', 'password' => 'dummy-hash',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'banned' => false,
            'transfer_enable' => 0,
        ]);
        Sanctum::actingAs($user);
        $this->getJson('/txapi/me/nodes')->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_node_projection_exposes_only_ui_fields_not_connection_secrets(): void
    {
        $item = ServerController::toPublicNode([
            'id' => 7, 'type' => 'vmess', 'name' => 'Node #7', 'version' => null,
            'rate' => 1.5, 'tags' => ['fast'], 'is_online' => true,
            'last_check_at' => 1790000000, 'cache_key' => 'private-cache',
            'password' => 'private-node-password',
            'server_key' => 'private-node-key', 'host' => 'private-host',
            'port' => 443, 'tls_key' => 'private-cert',
        ]);
        $this->assertSame(['id', 'type', 'version', 'name', 'rate', 'tags', 'is_online',
            'last_check_at'], array_keys($item));
        $this->assertSame(7, $item['id']);
        $this->assertFalse(array_key_exists('password', $item));
        $this->assertFalse(array_key_exists('server_key', $item));
    }
}
