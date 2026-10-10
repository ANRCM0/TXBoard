<?php

namespace App\Protocols\Definitions;

use App\Models\Server;
use App\Protocols\AbstractProtocolDefinition;
use App\Protocols\Shared\ProtocolFields;

class NaiveProtocol extends AbstractProtocolDefinition
{
    public function type(): string { return Server::TYPE_NAIVE; }
    public function label(): string { return 'NaiveProxy'; }

    public function defaults(): array
    {
        return ['tls' => 1, 'tls_settings' => ProtocolFields::tlsDefaults()];
    }

    public function formSchema(): array
    {
        return array_merge([
            ['key' => 'tls', 'label' => 'TLS', 'type' => 'select', 'options' => [
                ['value' => 0, 'label' => '关闭'],
                ['value' => 1, 'label' => '启用'],
            ]],
        ], ProtocolFields::tlsSchema('tls_settings', [['field' => 'tls', 'equals' => 1]]));
    }

    public function rules(): array
    {
        return array_merge(['tls' => 'required|integer|in:0,1'], ProtocolFields::tlsRules('tls_settings'));
    }

    public function buildNodeConfig(Server $node): array
    {
        $settings = $this->normalize($node->protocol_settings ?? []);
        return [
            ...$this->baseConfig($node),
            'server_port' => (int) $node->server_port,
            'tls' => (int) $settings['tls'],
            'tls_settings' => $settings['tls_settings'],
        ];
    }
}
