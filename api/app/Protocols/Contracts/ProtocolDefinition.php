<?php

namespace App\Protocols\Contracts;

use App\Models\Server;

interface ProtocolDefinition
{
    public function type(): string;

    public function label(): string;

    public function defaults(): array;

    public function formSchema(): array;

    /**
     * Validation rules relative to protocol_settings.
     */
    public function rules(): array;

    public function normalize(array $settings): array;

    /**
     * Build the protocol-specific configuration sent to a node.
     * Common routes/outbounds/certificate state are appended by ServerService.
     */
    public function buildNodeConfig(Server $node): array;

    public function metadata(): array;
}
