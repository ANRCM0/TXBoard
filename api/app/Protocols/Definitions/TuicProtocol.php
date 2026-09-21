<?php

namespace App\Protocols\Definitions;

use App\Models\Server;
use App\Protocols\AbstractProtocolDefinition;
use App\Protocols\Shared\ProtocolFields;

class TuicProtocol extends AbstractProtocolDefinition
{
    public function type(): string { return Server::TYPE_TUIC; }
    public function label(): string { return 'TUIC v5'; }

    public function defaults(): array
    {
        return [
            'version' => 5,
            'congestion_control' => 'cubic',
            'alpn' => ['h3'],
            'udp_relay_mode' => 'native',
            'tls' => ProtocolFields::tlsDefaults(),
        ];
    }

    public function formSchema(): array
    {
        return array_merge([
            ['key' => 'version', 'label' => '版本', 'type' => 'number'],
            ['key' => 'congestion_control', 'label' => '拥塞控制', 'type' => 'select', 'options' => [
                ['value' => 'cubic', 'label' => 'cubic'],
                ['value' => 'bbr', 'label' => 'bbr'],
                ['value' => 'new_reno', 'label' => 'new_reno'],
            ]],
            ['key' => 'alpn', 'label' => 'ALPN', 'type' => 'string-list', 'separator' => 'comma'],
            ['key' => 'udp_relay_mode', 'label' => 'UDP Relay Mode', 'type' => 'text'],
        ], ProtocolFields::tlsSchema('tls'));
    }

    public function rules(): array
    {
        return array_merge([
            'version' => 'nullable|integer',
            'congestion_control' => 'nullable|string',
            'alpn' => 'nullable|array',
            'alpn.*' => 'string',
            'udp_relay_mode' => 'nullable|string',
        ], ProtocolFields::tlsRules('tls'));
    }

    public function buildNodeConfig(Server $node): array
    {
        $settings = $this->normalize($node->protocol_settings ?? []);

        return [
            ...$this->baseConfig($node),
            'version' => (int) $settings['version'],
            'server_port' => (int) $node->server_port,
            'server_name' => data_get($settings, 'tls.server_name'),
            'congestion_control' => $settings['congestion_control'],
            'alpn' => $settings['alpn'],
            'udp_relay_mode' => $settings['udp_relay_mode'],
            'tls_settings' => $settings['tls'],
            'auth_timeout' => '3s',
            'zero_rtt_handshake' => false,
            'heartbeat' => '3s',
        ];
    }
}
