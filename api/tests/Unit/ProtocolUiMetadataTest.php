<?php

namespace Tests\Unit;

use App\Protocols\ProtocolRegistry;
use PHPUnit\Framework\TestCase;

final class ProtocolUiMetadataTest extends TestCase
{
    public function test_metadata_includes_native_certificate_fields_and_supported_transport_templates(): void
    {
        foreach ((new ProtocolRegistry())->metadata() as $protocol) {
            $certificate = array_column($protocol['certificate_schema'], null, 'key');
            $this->assertSame(['', 'none', 'http', 'dns', 'self', 'file', 'content'], array_column($certificate['cert_mode']['options'], 'value'));
            foreach (['domain', 'email', 'http_port', 'dns_provider', 'dns_env', 'cert_file', 'key_file', 'cert_content', 'key_content'] as $field) {
                $this->assertArrayHasKey($field, $certificate);
            }
            $this->assertSame('json-object', $certificate['dns_env']['type']);
            $this->assertSame([['field' => 'cert_mode', 'equals' => 'dns']], $certificate['dns_env']['visible_when']);
            foreach ($protocol['form_schema'] as $field) {
                $this->assertContains($field['type'], ['text', 'number', 'checkbox', 'textarea', 'select', 'json', 'json-array', 'json-object', 'string-list']);
                if ($field['key'] !== 'network_settings') continue;
                $this->assertSame(['ws', 'grpc', 'httpupgrade', 'h2', 'xhttp'], array_column($field['templates'], 'transport'));
                $this->assertSame(['serviceName' => 'grpc'], $field['templates'][1]['value']);
            }
        }
    }
}
