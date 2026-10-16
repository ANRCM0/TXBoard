<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NodeProtocolContractTest extends TestCase
{
    use RefreshDatabase;

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

}
