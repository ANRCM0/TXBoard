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

    public function test_generators_map_into_fields_of_the_same_protocol(): void
    {
        $generators = 0;

        foreach ((new ProtocolRegistry())->metadata() as $definition) {
            $keys = array_column($definition['form_schema'], 'key');

            foreach ($definition['form_schema'] as $field) {
                if (!isset($field['generator'])) {
                    continue;
                }

                $generators++;
                $generator = $field['generator'];
                $this->assertContains($generator['kind'], ['x25519', 'hex', 'ech'], "Unknown generator kind for {$field['key']}");
                $this->assertNotEmpty($generator['map']);

                foreach ($generator['map'] as $target) {
                    $this->assertContains($target, $keys, "{$field['key']} generator maps to unknown field {$target}");
                }
            }

        }

        $this->assertGreaterThan(0, $generators);
        $this->assertSame(14, $generators);
    }

    public function test_vless_encryption_generator_syncs_the_client_public_key(): void
    {
        $definition = (new ProtocolRegistry())->get('vless');
        $schema = collect($definition->formSchema())
            ->firstWhere('key', 'encryption.decryption');

        $this->assertSame('x25519', $schema['generator']['kind']);
        $this->assertSame('encryption.decryption', $schema['generator']['map']['private_key']);
        $this->assertSame('encryption.encryption', $schema['generator']['map']['public_key']);
    }

    public function test_normalization_casts_values_and_allows_list_replacement(): void
    {
        $tuic = (new ProtocolRegistry())->get('tuic');

        $normalized = $tuic->normalize([
            'version' => '5',
            'alpn' => [],
            'tls' => ['allow_insecure' => '1'],
        ]);

        $this->assertSame(5, $normalized['version']);
        $this->assertSame([], $normalized['alpn']);
        $this->assertTrue($normalized['tls']['allow_insecure']);
        $this->assertSame('cubic', $normalized['congestion_control']);
    }
}
