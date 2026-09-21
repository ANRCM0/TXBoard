<?php

namespace App\Protocols\Definitions;

use App\Models\Server;
use App\Protocols\AbstractProtocolDefinition;
use App\Protocols\Shared\ProtocolFields;

class VmessProtocol extends AbstractProtocolDefinition
{
    public function type(): string { return Server::TYPE_VMESS; }
    public function label(): string { return 'VMess'; }

    public function defaults(): array
    {
        return [
            'tls' => 0,
            'network' => 'tcp',
            'network_settings' => [],
            'rules' => [],
            'tls_settings' => ProtocolFields::tlsDefaults(),
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
                ]],
                ['key' => 'network', 'label' => '传输协议', 'type' => 'text', 'placeholder' => 'tcp / ws / grpc / httpupgrade / xhttp'],
                ['key' => 'network_settings', 'label' => 'Network Settings', 'type' => 'json', 'full' => true],
                ['key' => 'rules', 'label' => 'VMess Rules', 'type' => 'json-array', 'full' => true],
            ],
            ProtocolFields::tlsSchema('tls_settings', [['field' => 'tls', 'equals' => 1]]),
            ProtocolFields::utlsSchema(),
            ProtocolFields::multiplexSchema(),
        );
    }

    public function rules(): array
    {
        return array_merge([
            'tls' => 'required|integer|in:0,1',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'rules' => 'nullable|array',
        ], ProtocolFields::tlsRules('tls_settings'), ProtocolFields::multiplexRules(), ProtocolFields::utlsRules());
    }

    public function buildNodeConfig(Server $node): array
    {
        $settings = $this->normalize($node->protocol_settings ?? []);

        return [
            ...$this->baseConfig($node),
            'tls' => (int) $settings['tls'],
            'tls_settings' => $settings['tls_settings'],
            'multiplex' => data_get($settings, 'multiplex'),
        ];
    }
}
