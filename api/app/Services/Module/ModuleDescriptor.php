<?php

namespace App\Services\Module;

final readonly class ModuleDescriptor
{
    public function __construct(
        public ModuleManifest $manifest,
        public ModuleSource $source,
        public bool $installed,
        public bool $enabled,
        public ?bool $active,
        public ModuleHealth $health,
        public ?ModuleHealthDetails $healthDetails = null,
    ) {
    }

    public function toArray(): array
    {
        $descriptor = [
            'id' => $this->manifest->id,
            'name' => $this->manifest->name,
            'version' => $this->manifest->version,
        ];

        if ($this->manifest->description !== null) {
            $descriptor['description'] = $this->manifest->description;
        }

        if ($this->manifest->author !== null) {
            $descriptor['author'] = $this->manifest->author;
        }

        $descriptor += [
            'type' => $this->manifest->type->value,
            'source' => $this->source->value,
            'installed' => $this->installed,
            'enabled' => $this->enabled,
            'active' => $this->active,
            'health' => $this->health->value,
            'capabilities' => $this->manifest->capabilities,
            'compatibility' => [
                'txboard' => $this->manifest->txboardCompatibility,
            ],
        ];

        if ($this->healthDetails !== null) {
            $descriptor['health_details'] = $this->healthDetails->toArray();
        }

        if ($this->manifest->adminNavigation !== []) {
            $descriptor['admin'] = [
                'navigation' => $this->manifest->adminNavigation,
            ];
        }

        return $descriptor;
    }
}
