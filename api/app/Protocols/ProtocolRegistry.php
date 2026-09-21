<?php

namespace App\Protocols;

use App\Models\Server;
use App\Protocols\Contracts\ProtocolDefinition;
use App\Protocols\Definitions\ShadowsocksProtocol;
use App\Protocols\Definitions\VlessProtocol;

class ProtocolRegistry
{
    /** @var array<string, ProtocolDefinition> */
    private array $definitions;

    public function __construct()
    {
        $definitions = [
            new ShadowsocksProtocol(),
            new VlessProtocol(),
        ];

        $this->definitions = [];
        foreach ($definitions as $definition) {
            $this->definitions[$definition->type()] = $definition;
        }
    }

    public function get(?string $type): ?ProtocolDefinition
    {
        $normalized = Server::normalizeType($type);

        return $normalized ? ($this->definitions[$normalized] ?? null) : null;
    }

    public function has(?string $type): bool
    {
        return $this->get($type) !== null;
    }

    /**
     * @return array<string, ProtocolDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    public function metadata(): array
    {
        return array_values(array_map(
            static fn (ProtocolDefinition $definition) => $definition->metadata(),
            $this->definitions
        ));
    }
}
