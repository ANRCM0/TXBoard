<?php

namespace App\Protocols\Definitions;

use App\Models\Server;
use App\Protocols\AbstractProtocolDefinition;
use App\Protocols\Shared\ProtocolFields;

class HysteriaProtocol extends AbstractProtocolDefinition
{
    public function type(): string { return Server::TYPE_HYSTERIA; }
    public function label(): string { return 'Hysteria 2'; }

    public function defaults(): array
    {
        return [
            'version' => 2,
            'alpn' => 'h3',
            'bandwidth' => ['up' => null, 'down' => null],
            'obfs' => ['open' => false, 'type' => 'salamander', 'password' => ''],
            'tls' => ProtocolFields::tlsDefaults(),
            'hop_interval' => null,
        ];
    }

    public function formSchema(): array
    {
        $obfsOnly = [['field' => 'obfs.open', 'equals' => true]];

        return array_merge([
            ['key' => 'version', 'label' => '版本', 'type' => 'number'],
            ['key' => 'alpn', 'label' => 'ALPN', 'type' => 'text', 'placeholder' => 'h3'],
            ['key' => 'bandwidth.up', 'label' => '上行带宽 Mbps', 'type' => 'number'],
            ['key' => 'bandwidth.down', 'label' => '下行带宽 Mbps', 'type' => 'number'],
            ['key' => 'hop_interval', 'label' => 'Hop Interval', 'type' => 'number'],
            ['key' => 'obfs.open', 'label' => '启用 Obfs', 'type' => 'checkbox'],
            ['key' => 'obfs.type', 'label' => 'Obfs 类型', 'type' => 'text', 'visible_when' => $obfsOnly],
            ['key' => 'obfs.password', 'label' => 'Obfs 密码', 'type' => 'text', 'visible_when' => $obfsOnly],
        ], ProtocolFields::tlsSchema('tls'));
    }

    public function rules(): array
    {
        return array_merge([
            'version' => 'required|integer',
            'alpn' => 'nullable|string',
            'obfs' => 'nullable|array',
            'obfs.open' => 'nullable|boolean',
            'obfs.type' => 'string|nullable',
            'obfs.password' => 'string|nullable',
            'bandwidth' => 'nullable|array',
            'bandwidth.up' => 'nullable|integer',
            'bandwidth.down' => 'nullable|integer',
            'hop_interval' => 'integer|nullable',
        ], ProtocolFields::tlsRules('tls'));
    }

    public function buildNodeConfig(Server $node): array
    {
        $settings = $this->normalize($node->protocol_settings ?? []);
        $version = (int) $settings['version'];

        return [
            ...$this->baseConfig($node),
            'server_port' => (int) $node->server_port,
            'version' => $version,
            'host' => $node->host,
            'server_name' => data_get($settings, 'tls.server_name'),
            'tls_settings' => $settings['tls'],
            'alpn' => $settings['alpn'] ?? null,
            'hop_interval' => $settings['hop_interval'] ?? null,
            'up_mbps' => (int) data_get($settings, 'bandwidth.up', 0),
            'down_mbps' => (int) data_get($settings, 'bandwidth.down', 0),
            ...match ($version) {
                1 => ['obfs' => data_get($settings, 'obfs.password')],
                2 => [
                    'obfs' => data_get($settings, 'obfs.open') ? data_get($settings, 'obfs.type') : null,
                    'obfs-password' => data_get($settings, 'obfs.password'),
                ],
                default => [],
            },
        ];
    }
}
