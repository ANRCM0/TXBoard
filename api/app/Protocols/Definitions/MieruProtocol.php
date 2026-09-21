<?php

namespace App\Protocols\Definitions;

use App\Models\Server;
use App\Protocols\AbstractProtocolDefinition;
use App\Protocols\Shared\ProtocolFields;

class MieruProtocol extends AbstractProtocolDefinition
{
    public function type(): string { return Server::TYPE_MIERU; }
    public function label(): string { return 'Mieru'; }

    public function defaults(): array
    {
        return [
            'transport' => 'TCP',
            'traffic_pattern' => '',
            'multiplex' => ProtocolFields::multiplexDefaults(),
        ];
    }

    public function formSchema(): array
    {
        return array_merge([
            ['key' => 'transport', 'label' => 'Transport', 'type' => 'select', 'options' => [
                ['value' => 'TCP', 'label' => 'TCP'],
                ['value' => 'UDP', 'label' => 'UDP'],
            ]],
            ['key' => 'traffic_pattern', 'label' => 'Traffic Pattern', 'type' => 'text'],
        ], ProtocolFields::multiplexSchema());
    }

    public function rules(): array
    {
        return array_merge([
            'transport' => 'required|string|in:TCP,UDP',
            'traffic_pattern' => 'nullable|string',
        ], ProtocolFields::multiplexRules());
    }

    public function buildNodeConfig(Server $node): array
    {
        $settings = $this->normalize($node->protocol_settings ?? []);
        return [
            ...$this->baseConfig($node),
            'server_port' => (int) $node->server_port,
            'transport' => $settings['transport'],
            'traffic_pattern' => $settings['traffic_pattern'],
            'multiplex' => data_get($settings, 'multiplex'),
        ];
    }
}
