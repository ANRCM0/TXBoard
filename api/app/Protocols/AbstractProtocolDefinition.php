<?php

namespace App\Protocols;

use App\Models\Server;
use App\Protocols\Contracts\ProtocolDefinition;

abstract class AbstractProtocolDefinition implements ProtocolDefinition
{
    public function normalize(array $settings): array
    {
        return array_replace_recursive($this->defaults(), $settings);
    }

    protected function baseConfig(Server $node): array
    {
        $settings = $this->normalize($node->protocol_settings ?? []);

        return [
            'protocol' => $this->type(),
            'listen_ip' => '0.0.0.0',
            'server_port' => (int) $node->server_port,
            'network' => data_get($settings, 'network'),
            'networkSettings' => data_get($settings, 'network_settings') ?: null,
        ];
    }

    public function metadata(): array
    {
        return [
            'type' => $this->type(),
            'label' => $this->label(),
            'schema_version' => 1,
            'defaults' => $this->defaults(),
            'form_schema' => $this->formSchema(),
        ];
    }
}
