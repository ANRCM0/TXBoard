<?php

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\ServerService;
use Tests\TestCase;

class ServerServiceNodeConfigTest extends TestCase
{
    public function test_build_node_config_propagates_explicit_cert_none(): void
    {
        $node = new Server();
        $node->forceFill([
            'type' => Server::TYPE_SOCKS,
            'host' => '',
            'server_port' => 1080,
            'protocol_settings' => [
                'network' => null,
                'network_settings' => null,
                'tls' => 0,
                'tls_settings' => null,
            ],
            'cert_config' => [
                'cert_mode' => 'none',
            ],
        ]);

        $config = ServerService::buildNodeConfig($node);

        $this->assertArrayHasKey('cert_config', $config);
        $this->assertSame(['cert_mode' => 'none'], $config['cert_config']);
    }
}
