<?php

namespace App\Protocols\Definitions;

use App\Models\Server;
use App\Protocols\AbstractProtocolDefinition;
use App\Protocols\Shared\ProtocolFields;

class AnyTlsProtocol extends AbstractProtocolDefinition
{
    public function type(): string { return Server::TYPE_ANYTLS; }
    public function label(): string { return 'AnyTLS'; }

    public function defaults(): array
    {
        return [
            'alpn' => 'h2,http/1.1',
            'padding_scheme' => [
                'stop=8',
                '0=30-30',
                '1=100-400',
                '2=400-500,c,500-1000,c,500-1000,c,500-1000,c,500-1000',
                '3=9-9,500-1000',
                '4=500-1000',
                '5=500-1000',
                '6=500-1000',
                '7=500-1000',
            ],
            'tls' => ProtocolFields::tlsDefaults(),
        ];
    }

    public function formSchema(): array
    {
        return array_merge([
            ['key' => 'alpn', 'label' => 'ALPN', 'type' => 'text', 'full' => true],
            ['key' => 'padding_scheme', 'label' => 'Padding Scheme（每行一项）', 'type' => 'string-list', 'separator' => 'newline', 'full' => true],
        ], ProtocolFields::tlsSchema('tls'));
    }

    public function rules(): array
    {
        return array_merge([
            'alpn' => 'nullable|string',
            'padding_scheme' => 'nullable|array',
            'padding_scheme.*' => 'string',
        ], ProtocolFields::tlsRules('tls'));
    }

    public function buildNodeConfig(Server $node): array
    {
        $settings = $this->normalize($node->protocol_settings ?? []);

        return [
            ...$this->baseConfig($node),
            'server_port' => (int) $node->server_port,
            'server_name' => data_get($settings, 'tls.server_name'),
            'tls_settings' => $settings['tls'],
            'alpn' => $settings['alpn'] ?? null,
            'padding_scheme' => $settings['padding_scheme'],
        ];
    }
}
