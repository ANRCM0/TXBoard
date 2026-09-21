<?php

namespace Tests\Unit;

use App\Protocols\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

class ProtocolRegistryTest extends TestCase
{
    public function test_registry_exposes_initial_protocol_models(): void
    {
        $registry = new ProtocolRegistry();

        $this->assertNotNull($registry->get('shadowsocks'));
        $this->assertNotNull($registry->get('vless'));
        $this->assertSame('vless', $registry->get('VLESS')?->type());
        $this->assertNull($registry->get('tuic'));
    }

    public function test_vless_defaults_are_merged_without_losing_nested_defaults(): void
    {
        $definition = (new ProtocolRegistry())->get('vless');

        $normalized = $definition->normalize([
            'network' => 'ws',
            'multiplex' => ['enabled' => true],
        ]);

        $this->assertSame('ws', $normalized['network']);
        $this->assertTrue($normalized['multiplex']['enabled']);
        $this->assertSame('yamux', $normalized['multiplex']['protocol']);
        $this->assertSame(443, $normalized['reality_settings']['server_port']);
    }
}
