<?php

namespace App\Protocols\Definitions;

use App\Models\Server;
use App\Protocols\AbstractProtocolDefinition;

class VlessProtocol extends AbstractProtocolDefinition
{
    public function type(): string
    {
        return Server::TYPE_VLESS;
    }

    public function label(): string
    {
        return 'VLESS';
    }

    public function defaults(): array
    {
        return [
            'tls' => 0,
            'network' => 'tcp',
            'network_settings' => [],
            'flow' => '',
            'encryption' => [
                'enabled' => false,
                'encryption' => '',
                'decryption' => '',
            ],
            'tls_settings' => $this->tlsDefaults(),
            'reality_settings' => [
                'server_name' => '',
                'server_port' => 443,
                'public_key' => '',
                'private_key' => '',
                'short_id' => '',
                'allow_insecure' => false,
            ],
            'multiplex' => [
                'enabled' => false,
                'protocol' => 'yamux',
                'max_connections' => null,
                'min_streams' => null,
                'max_streams' => null,
                'padding' => false,
                'brutal' => [
                    'enabled' => false,
                    'up_mbps' => null,
                    'down_mbps' => null,
                ],
            ],
            'utls' => [
                'enabled' => false,
                'fingerprint' => 'chrome',
            ],
        ];
    }

    private function tlsDefaults(): array
    {
        return [
            'server_name' => '',
            'allow_insecure' => false,
            'ech' => [
                'enabled' => false,
                'config' => '',
                'query_server_name' => '',
                'key' => '',
                'key_path' => '',
                'config_path' => '',
            ],
        ];
    }

    public function formSchema(): array
    {
        $tlsOnly = [['field' => 'tls', 'equals' => 1]];
        $realityOnly = [['field' => 'tls', 'equals' => 2]];
        $echOnly = [
            ['field' => 'tls', 'equals' => 1],
            ['field' => 'tls_settings.ech.enabled', 'equals' => true],
        ];
        $encryptionOnly = [['field' => 'encryption.enabled', 'equals' => true]];
        $brutalOnly = [['field' => 'multiplex.brutal.enabled', 'equals' => true]];

        return [
            [
                'key' => 'tls',
                'label' => 'TLS 模式',
                'type' => 'select',
                'options' => [
                    ['value' => 0, 'label' => '关闭'],
                    ['value' => 1, 'label' => 'TLS'],
                    ['value' => 2, 'label' => 'Reality'],
                ],
            ],
            ['key' => 'network', 'label' => '传输协议', 'type' => 'text', 'placeholder' => 'tcp / ws / grpc / httpupgrade / xhttp'],
            ['key' => 'flow', 'label' => 'Flow', 'type' => 'text', 'placeholder' => 'xtls-rprx-vision'],
            ['key' => 'network_settings', 'label' => 'Network Settings', 'type' => 'json', 'full' => true],

            ['key' => 'tls_settings.server_name', 'label' => 'SNI / Server Name', 'type' => 'text', 'visible_when' => $tlsOnly],
            ['key' => 'tls_settings.allow_insecure', 'label' => '跳过证书验证', 'type' => 'checkbox', 'visible_when' => $tlsOnly],
            ['key' => 'tls_settings.ech.enabled', 'label' => '启用 ECH', 'type' => 'checkbox', 'full' => true, 'visible_when' => $tlsOnly],
            ['key' => 'tls_settings.ech.config', 'label' => 'ECH Config', 'type' => 'textarea', 'full' => true, 'visible_when' => $echOnly],
            ['key' => 'tls_settings.ech.query_server_name', 'label' => 'ECH Query Server Name', 'type' => 'text', 'visible_when' => $echOnly],
            ['key' => 'tls_settings.ech.key_path', 'label' => 'ECH Key Path', 'type' => 'text', 'visible_when' => $echOnly],
            ['key' => 'tls_settings.ech.key', 'label' => 'ECH Key', 'type' => 'textarea', 'full' => true, 'visible_when' => $echOnly],
            ['key' => 'tls_settings.ech.config_path', 'label' => 'ECH Config Path', 'type' => 'text', 'full' => true, 'visible_when' => $echOnly],

            ['key' => 'reality_settings.server_name', 'label' => 'Reality SNI', 'type' => 'text', 'visible_when' => $realityOnly],
            ['key' => 'reality_settings.server_port', 'label' => 'Reality 目标端口', 'type' => 'number', 'min' => 1, 'max' => 65535, 'visible_when' => $realityOnly],
            ['key' => 'reality_settings.public_key', 'label' => 'Reality Public Key', 'type' => 'text', 'full' => true, 'visible_when' => $realityOnly],
            ['key' => 'reality_settings.private_key', 'label' => 'Reality Private Key', 'type' => 'text', 'full' => true, 'visible_when' => $realityOnly],
            ['key' => 'reality_settings.short_id', 'label' => 'Reality Short ID', 'type' => 'text', 'visible_when' => $realityOnly],
            ['key' => 'reality_settings.allow_insecure', 'label' => 'Reality 跳过证书验证', 'type' => 'checkbox', 'visible_when' => $realityOnly],

            ['key' => 'utls.enabled', 'label' => '启用 uTLS', 'type' => 'checkbox'],
            ['key' => 'utls.fingerprint', 'label' => 'uTLS 指纹', 'type' => 'text', 'placeholder' => 'chrome'],

            ['key' => 'multiplex.enabled', 'label' => '启用 Multiplex', 'type' => 'checkbox'],
            ['key' => 'multiplex.protocol', 'label' => '复用协议', 'type' => 'text'],
            ['key' => 'multiplex.max_connections', 'label' => '最大连接数', 'type' => 'number'],
            ['key' => 'multiplex.min_streams', 'label' => '最小流数', 'type' => 'number'],
            ['key' => 'multiplex.max_streams', 'label' => '最大流数', 'type' => 'number'],
            ['key' => 'multiplex.padding', 'label' => 'Multiplex Padding', 'type' => 'checkbox'],
            ['key' => 'multiplex.brutal.enabled', 'label' => '启用 Brutal', 'type' => 'checkbox', 'full' => true],
            ['key' => 'multiplex.brutal.up_mbps', 'label' => 'Brutal 上行 Mbps', 'type' => 'number', 'visible_when' => $brutalOnly],
            ['key' => 'multiplex.brutal.down_mbps', 'label' => 'Brutal 下行 Mbps', 'type' => 'number', 'visible_when' => $brutalOnly],

            ['key' => 'encryption.enabled', 'label' => '启用 VLESS Encryption', 'type' => 'checkbox', 'full' => true],
            ['key' => 'encryption.encryption', 'label' => 'Encryption / Client Public Key', 'type' => 'text', 'visible_when' => $encryptionOnly],
            ['key' => 'encryption.decryption', 'label' => 'Decryption / Server Private Key', 'type' => 'text', 'visible_when' => $encryptionOnly],
        ];
    }

    public function rules(): array
    {
        return [
            'tls' => 'required|integer|in:0,1,2',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'flow' => 'nullable|string',
            'encryption' => 'nullable|array',
            'encryption.enabled' => 'nullable|boolean',
            'encryption.encryption' => 'nullable|string',
            'encryption.decryption' => 'nullable|string',

            'tls_settings' => 'nullable|array',
            'tls_settings.server_name' => 'nullable|string',
            'tls_settings.allow_insecure' => 'nullable|boolean',
            'tls_settings.ech' => 'nullable|array',
            'tls_settings.ech.enabled' => 'nullable|boolean',
            'tls_settings.ech.config' => 'nullable|string',
            'tls_settings.ech.query_server_name' => 'nullable|string',
            'tls_settings.ech.key' => 'nullable|string',
            'tls_settings.ech.key_path' => 'nullable|string',
            'tls_settings.ech.config_path' => 'nullable|string',

            'reality_settings' => 'nullable|array',
            'reality_settings.allow_insecure' => 'nullable|boolean',
            'reality_settings.server_name' => 'nullable|string',
            'reality_settings.server_port' => 'nullable|integer|min:1|max:65535',
            'reality_settings.public_key' => 'nullable|string',
            'reality_settings.private_key' => 'nullable|string',
            'reality_settings.short_id' => 'nullable|string',

            'multiplex' => 'nullable|array',
            'multiplex.enabled' => 'nullable|boolean',
            'multiplex.protocol' => 'nullable|string',
            'multiplex.max_connections' => 'nullable|integer',
            'multiplex.min_streams' => 'nullable|integer',
            'multiplex.max_streams' => 'nullable|integer',
            'multiplex.padding' => 'nullable|boolean',
            'multiplex.brutal' => 'nullable|array',
            'multiplex.brutal.enabled' => 'nullable|boolean',
            'multiplex.brutal.up_mbps' => 'nullable|integer',
            'multiplex.brutal.down_mbps' => 'nullable|integer',

            'utls' => 'nullable|array',
            'utls.enabled' => 'nullable|boolean',
            'utls.fingerprint' => 'nullable|string',
        ];
    }

    public function buildNodeConfig(Server $node): array
    {
        $settings = $this->normalize($node->protocol_settings ?? []);
        $tlsMode = (int) ($settings['tls'] ?? 0);

        return [
            ...$this->baseConfig($node),
            'tls' => $tlsMode,
            'flow' => $settings['flow'] ?? null,
            'decryption' => data_get($settings, 'encryption.enabled')
                ? data_get($settings, 'encryption.decryption')
                : null,
            'tls_settings' => $tlsMode === 2
                ? ($settings['reality_settings'] ?? [])
                : ($settings['tls_settings'] ?? []),
            'multiplex' => data_get($settings, 'multiplex'),
        ];
    }
}
