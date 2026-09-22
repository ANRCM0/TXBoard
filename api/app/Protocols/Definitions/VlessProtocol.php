<?php

namespace App\Protocols\Definitions;

use App\Models\Server;
use App\Protocols\AbstractProtocolDefinition;
use App\Protocols\Shared\ProtocolFields;

class VlessProtocol extends AbstractProtocolDefinition
{
    public function type(): string { return Server::TYPE_VLESS; }
    public function label(): string { return 'VLESS'; }

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
            'tls_settings' => ProtocolFields::tlsDefaults(),
            'reality_settings' => ProtocolFields::realityDefaults(),
            'multiplex' => ProtocolFields::multiplexDefaults(),
            'utls' => ProtocolFields::utlsDefaults(),
        ];
    }

    public function formSchema(): array
    {
        $encryptionOnly = [['field' => 'encryption.enabled', 'equals' => true]];

        return array_merge(
            [
                ['key' => 'tls', 'label' => 'TLS 模式', 'type' => 'select', 'options' => [
                    ['value' => 0, 'label' => '关闭'],
                    ['value' => 1, 'label' => 'TLS'],
                    ['value' => 2, 'label' => 'Reality'],
                ]],
                ['key' => 'network', 'label' => '传输协议', 'type' => 'text', 'placeholder' => 'tcp / ws / grpc / httpupgrade / xhttp'],
                ['key' => 'flow', 'label' => 'Flow', 'type' => 'text', 'placeholder' => 'xtls-rprx-vision'],
                ['key' => 'network_settings', 'label' => 'Network Settings', 'type' => 'json', 'full' => true],
            ],
            ProtocolFields::tlsSchema('tls_settings', [['field' => 'tls', 'equals' => 1]]),
            ProtocolFields::realitySchema([['field' => 'tls', 'equals' => 2]]),
            ProtocolFields::utlsSchema(),
            ProtocolFields::multiplexSchema(),
            [
                ['key' => 'encryption.enabled', 'label' => '启用 VLESS Encryption', 'type' => 'checkbox', 'full' => true],
                ['key' => 'encryption.encryption', 'label' => 'Encryption / Client Public Key', 'type' => 'text', 'visible_when' => $encryptionOnly],
                ['key' => 'encryption.decryption', 'label' => 'Decryption / Server Private Key', 'type' => 'text', 'visible_when' => $encryptionOnly, 'generator' => ProtocolFields::x25519Generator('encryption.decryption', 'encryption.encryption', '生成 VLESS Encryption 密钥对')],
            ],
        );
    }

    public function rules(): array
    {
        return array_merge([
            'tls' => 'required|integer|in:0,1,2',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'flow' => 'nullable|string',
            'encryption' => 'nullable|array',
            'encryption.enabled' => 'nullable|boolean',
            'encryption.encryption' => 'nullable|string',
            'encryption.decryption' => 'nullable|string',
        ], ProtocolFields::tlsRules('tls_settings'), ProtocolFields::realityRules(), ProtocolFields::multiplexRules(), ProtocolFields::utlsRules());
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
