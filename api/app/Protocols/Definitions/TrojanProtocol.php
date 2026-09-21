<?php

namespace App\Protocols\Definitions;

use App\Models\Server;
use App\Protocols\AbstractProtocolDefinition;
use App\Protocols\Shared\ProtocolFields;

class TrojanProtocol extends AbstractProtocolDefinition
{
    public function type(): string { return Server::TYPE_TROJAN; }
    public function label(): string { return 'Trojan'; }

    public function defaults(): array
    {
        return [
            'tls' => 1,
            'network' => 'tcp',
            'network_settings' => [],
            'server_name' => '',
            'allow_insecure' => false,
            'tls_settings' => ProtocolFields::tlsDefaults(),
            'reality_settings' => ProtocolFields::realityDefaults(),
            'multiplex' => ProtocolFields::multiplexDefaults(),
            'utls' => ProtocolFields::utlsDefaults(),
        ];
    }

    public function formSchema(): array
    {
        return array_merge(
            [
                ['key' => 'tls', 'label' => 'TLS 模式', 'type' => 'select', 'options' => [
                    ['value' => 0, 'label' => '关闭'],
                    ['value' => 1, 'label' => 'TLS'],
                    ['value' => 2, 'label' => 'Reality'],
                ]],
                ['key' => 'network', 'label' => '传输协议', 'type' => 'text', 'placeholder' => 'tcp / ws / grpc / httpupgrade / xhttp'],
                ['key' => 'network_settings', 'label' => 'Network Settings', 'type' => 'json', 'full' => true],
            ],
            ProtocolFields::tlsSchema('tls_settings', [['field' => 'tls', 'equals' => 1]]),
            ProtocolFields::realitySchema([['field' => 'tls', 'equals' => 2]]),
            ProtocolFields::utlsSchema(),
            ProtocolFields::multiplexSchema(),
        );
    }

    public function rules(): array
    {
        return array_merge([
            'tls' => 'nullable|integer|in:0,1,2',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'server_name' => 'nullable|string',
            'allow_insecure' => 'nullable|boolean',
        ], ProtocolFields::tlsRules('tls_settings'), ProtocolFields::realityRules(), ProtocolFields::multiplexRules(), ProtocolFields::utlsRules());
    }

    public function buildNodeConfig(Server $node): array
    {
        $settings = $this->normalize($node->protocol_settings ?? []);
        $tlsMode = (int) $settings['tls'];

        return [
            ...$this->baseConfig($node),
            'host' => $node->host,
            'server_name' => data_get($settings, 'tls_settings.server_name'),
            'multiplex' => data_get($settings, 'multiplex'),
            'tls' => $tlsMode,
            'tls_settings' => $tlsMode === 2
                ? $settings['reality_settings']
                : $settings['tls_settings'],
        ];
    }
}
