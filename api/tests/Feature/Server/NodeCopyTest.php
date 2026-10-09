<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NodeCopyTest extends TestCase
{
    use RefreshDatabase;

    private string $securePath;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::create([
            'email' => 'node-copy-admin@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000301',
            'token' => str_repeat('c', 32),
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

    public function test_copy_returns_the_new_id_and_preserves_protocol_settings(): void
    {
        $server = Server::create([
            'name' => 'Tokyo-01',
            'type' => 'vless',
            'host' => 'tokyo.example.com',
            'port' => 443,
            'server_port' => 8443,
            'rate' => 1,
            'show' => 1,
            'tags' => ['jp'],
            'protocol_settings' => [
                'tls' => 2,
                'reality_settings' => [
                    'server_name' => 'www.microsoft.com',
                    'private_key' => 'PRIVATE',
                    'public_key' => 'PUBLIC',
                ],
            ],
        ]);

        $response = $this->postJson("/txapi/admin/{$this->securePath}/network-nodes/{$server->id}/copy");

        $response->assertOk();

        $copied = Server::find((int) $response->json('data'));
        $this->assertNotNull($copied, 'copy must resolve to a persisted node');
        $this->assertNotSame($server->id, $copied->id);
        $this->assertSame('Tokyo-01', $copied->name);
        // Copies stay out of subscriptions until the operator renames/re-enables them.
        $this->assertFalse((bool) $copied->show);
        $this->assertSame('PRIVATE', data_get($copied->protocol_settings, 'reality_settings.private_key'));
        $this->assertSame('PUBLIC', data_get($copied->protocol_settings, 'reality_settings.public_key'));
        $this->assertSame(0, (int) $copied->u);
        $this->assertSame(0, (int) $copied->d);
    }

    public function test_copy_rejects_unknown_nodes(): void
    {
        $this->postJson("/txapi/admin/{$this->securePath}/network-nodes/424242/copy")
            ->assertStatus(404);
    }
}