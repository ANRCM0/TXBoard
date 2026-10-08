<?php

namespace Tests\Feature\Server;

use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class NodeProtocolContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_snapshot_only_contains_active_entitled_users_in_stable_order(): void
    {
        Bus::fake();
        $plan = Plan::create([
            'name' => 'Protocol Test Plan', 'transfer_enable' => 1,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'prices' => [Plan::PERIOD_MONTHLY => 1],
            'sell' => 1, 'show' => 1, 'renew' => 1,
        ]);
        $node = $this->node(Server::TYPE_VMESS);
        $eligible = $this->user('eligible', $plan->id, [
            'group_id' => 1, 'u' => 50, 'd' => 50, 'transfer_enable' => 200,
        ]);
        $this->user('wrong-group', $plan->id, ['group_id' => 2, 'transfer_enable' => 200]);
        $this->user('banned', $plan->id, ['group_id' => 1, 'banned' => 1, 'transfer_enable' => 200]);
        $this->user('expired', $plan->id, ['group_id' => 1, 'expired_at' => time() - 10, 'transfer_enable' => 200]);
        $this->user('exhausted', $plan->id, ['group_id' => 1, 'u' => 120, 'd' => 80, 'transfer_enable' => 200]);
        $this->user('no-plan', null, ['group_id' => 1, 'transfer_enable' => 200]);
        $snapshot = ServerService::getAvailableUsers($node)->toArray();
        $this->assertSame([$eligible->id], array_column($snapshot, 'id'));
        $this->assertSame(['id', 'uuid', 'speed_limit', 'device_limit'], array_keys($snapshot[0]));
    }

    public function test_common_protocols_emit_fields_consumed_by_tx_node(): void
    {
        foreach ([Server::TYPE_VMESS, Server::TYPE_VLESS, Server::TYPE_TROJAN,
            Server::TYPE_SHADOWSOCKS, Server::TYPE_HYSTERIA] as $type) {
            $config = ServerService::buildNodeConfig($this->node($type));
            $this->assertSame($type, $config['protocol']);
            $this->assertSame(8443, $config['server_port']);
            $this->assertSame('0.0.0.0', $config['listen_ip']);
        }
        $this->assertArrayHasKey('networkSettings',
            ServerService::buildNodeConfig($this->node(Server::TYPE_VLESS)));
        $this->assertArrayHasKey('tls_settings',
            ServerService::buildNodeConfig($this->node(Server::TYPE_TROJAN)));
        $this->assertArrayHasKey('server_key',
            ServerService::buildNodeConfig($this->node(Server::TYPE_SHADOWSOCKS)));
        $this->assertArrayHasKey('obfs-password',
            ServerService::buildNodeConfig($this->node(Server::TYPE_HYSTERIA)));
    }

    private function node(string $type): Server
    {
        $node = new Server();
        $node->forceFill([
            'type' => $type, 'name' => 'protocol-node', 'host' => '127.0.0.1',
            'port' => '8443', 'server_port' => 8443, 'rate' => 1,
            'group_ids' => [1], 'enabled' => true, 'created_at' => now(),
            'protocol_settings' => [],
        ]);
        return $node;
    }

    private function user(string $name, ?int $plan, array $extra = []): User
    {
        return User::create(array_merge([
            'email' => $name . '@example.test', 'password' => 'password',
            'uuid' => '11111111-1111-1111-1111-' . str_pad((string) (User::count() + 1), 12, '0', STR_PAD_LEFT),
            'token' => str_pad($name, 32, 'a'),
            'plan_id' => $plan, 'group_id' => 1,
            'banned' => 0, 'u' => 0, 'd' => 0,
            'transfer_enable' => 200, 'expired_at' => time() + 3600,
            'created_at' => time(), 'updated_at' => time(),
        ], $extra));
    }
}
