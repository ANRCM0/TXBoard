<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Protocols\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

class ProtocolRegistryTest extends TestCase
{
    public function test_registry_covers_every_supported_protocol(): void
    {
        $registry = new ProtocolRegistry();

        foreach (Server::VALID_TYPES as $type) {
            $this->assertNotNull($registry->get($type), "Missing protocol definition for {$type}");
        }

        $this->assertSame(count(Server::VALID_TYPES), count($registry->metadata()));
        $this->assertSame('vless', $registry->get('VLESS')?->type());
        $this->assertSame('hysteria', $registry->get('hysteria2')?->type());
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

    public function test_every_protocol_exposes_defaults_form_schema_and_rules(): void
    {
        foreach ((new ProtocolRegistry())->all() as $definition) {
            $this->assertNotEmpty($definition->defaults());
            $this->assertNotEmpty($definition->formSchema());
            $this->assertIsArray($definition->rules());
        }
    }
}
