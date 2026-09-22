<?php

namespace App\Services\Module;

use InvalidArgumentException;

final readonly class ModuleManifest
{
    public const SCHEMA_VERSION = 1;

    private const ID_PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';
    private const VERSION_PATTERN = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/';
    private const NAV_ID_PATTERN = '/^[A-Za-z0-9_-]+$/';
    private const NAV_PATH_PATTERN = '/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/';

    /**
     * @param list<string> $capabilities
     * @param array<string, string> $dependencies
     * @param list<array{id: string, title: string, path: string, icon?: string, order?: int}> $adminNavigation
     */
    private function __construct(
        public string $id,
        public string $name,
        public string $version,
        public ModuleType $type,
        public string $txboardCompatibility,
        public array $capabilities,
        public array $dependencies = [],
        public array $adminNavigation = [],
        public ?string $description = null,
        public ?string $author = null,
    ) {
    }

    public static function fromArray(array $manifest): self
    {
        self::assertAllowedKeys(
            $manifest,
            ['schema', 'module', 'compatibility', 'capabilities', 'dependencies', 'admin'],
            'manifest'
        );

        if (($manifest['schema'] ?? null) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Module Package v1 requires schema=1');
        }

        $module = self::requireArray($manifest, 'module');
        self::assertAllowedKeys(
            $module,
            ['id', 'name', 'version', 'type', 'description', 'author'],
            'module'
        );

        $id = self::requireString($module, 'id', 'module.id');
        self::assertModuleId($id);

        $name = self::requireString($module, 'name', 'module.name');
        self::assertLength($name, 1, 120, 'module.name');

        $version = self::requireString($module, 'version', 'module.version');
        if (!preg_match(self::VERSION_PATTERN, $version)) {
            throw new InvalidArgumentException('module.version must be a valid SemVer version');
        }

        $typeValue = self::requireString($module, 'type', 'module.type');
        $type = ModuleType::tryFrom($typeValue);
        if (!$type) {
            throw new InvalidArgumentException('module.type is not supported');
        }

        $description = self::optionalString($module, 'description', 1000, 'module.description');
        $author = self::optionalString($module, 'author', 120, 'module.author');

        $compatibility = self::requireArray($manifest, 'compatibility');
        self::assertAllowedKeys($compatibility, ['txboard'], 'compatibility');
        $txboardCompatibility = self::requireString(
            $compatibility,
            'txboard',
            'compatibility.txboard'
        );
        self::assertLength($txboardCompatibility, 1, 120, 'compatibility.txboard');

        $capabilities = self::parseCapabilities($manifest['capabilities'] ?? null);
        $dependencies = self::parseDependencies($manifest['dependencies'] ?? []);
        $adminNavigation = self::parseAdmin($manifest['admin'] ?? []);

        return new self(
            id: $id,
            name: $name,
            version: $version,
            type: $type,
            txboardCompatibility: $txboardCompatibility,
            capabilities: $capabilities,
            dependencies: $dependencies,
            adminNavigation: $adminNavigation,
            description: $description,
            author: $author,
        );
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function toArray(): array
    {
        $module = [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'type' => $this->type->value,
        ];

        if ($this->description !== null) {
            $module['description'] = $this->description;
        }
        if ($this->author !== null) {
            $module['author'] = $this->author;
        }

        $manifest = [
            'schema' => self::SCHEMA_VERSION,
            'module' => $module,
            'compatibility' => [
                'txboard' => $this->txboardCompatibility,
            ],
            'capabilities' => $this->capabilities,
        ];

        if ($this->dependencies !== []) {
            $manifest['dependencies'] = $this->dependencies;
        }

        if ($this->adminNavigation !== []) {
            $manifest['admin'] = [
                'navigation' => $this->adminNavigation,
            ];
        }

        return $manifest;
    }

    /**
     * @return list<string>
     */
    private static function parseCapabilities(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('capabilities must be an array');
        }

        $capabilities = [];
        foreach ($value as $capability) {
            if (!is_string($capability) || !ModuleCapability::isKnown($capability)) {
                throw new InvalidArgumentException('capabilities contains an unsupported capability');
            }
            if (in_array($capability, $capabilities, true)) {
                throw new InvalidArgumentException('capabilities must not contain duplicates');
            }
            $capabilities[] = $capability;
        }

        return $capabilities;
    }

    /**
     * @return array<string, string>
     */
    private static function parseDependencies(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw new InvalidArgumentException('dependencies must be an object');
        }

        $dependencies = [];
        foreach ($value as $moduleId => $constraint) {
            if (!is_string($moduleId)) {
                throw new InvalidArgumentException('dependency module IDs must be strings');
            }
            self::assertModuleId($moduleId);

            if (!is_string($constraint) || trim($constraint) === '') {
                throw new InvalidArgumentException('dependency constraints must be non-empty strings');
            }
            self::assertLength($constraint, 1, 120, 'dependency constraint');
            $dependencies[$moduleId] = trim($constraint);
        }

        return $dependencies;
    }

    /**
     * @return list<array{id: string, title: string, path: string, icon?: string, order?: int}>
     */
    private static function parseAdmin(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw new InvalidArgumentException('admin must be an object');
        }

        self::assertAllowedKeys($value, ['navigation'], 'admin');

        $navigation = $value['navigation'] ?? [];
        if (!is_array($navigation) || !array_is_list($navigation)) {
            throw new InvalidArgumentException('admin.navigation must be an array');
        }

        $seenIds = [];
        $normalized = [];

        foreach ($navigation as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new InvalidArgumentException('admin.navigation entries must be objects');
            }

            self::assertAllowedKeys($entry, ['id', 'title', 'path', 'icon', 'order'], 'admin.navigation entry');

            $id = self::requireString($entry, 'id', 'admin.navigation.id');
            self::assertLength($id, 1, 64, 'admin.navigation.id');
            if (!preg_match(self::NAV_ID_PATTERN, $id)) {
                throw new InvalidArgumentException('admin.navigation.id is invalid');
            }
            if (isset($seenIds[$id])) {
                throw new InvalidArgumentException('admin.navigation IDs must be unique');
            }
            $seenIds[$id] = true;

            $title = self::requireString($entry, 'title', 'admin.navigation.title');
            self::assertLength($title, 1, 120, 'admin.navigation.title');

            $path = self::requireString($entry, 'path', 'admin.navigation.path');
            self::assertLength($path, 1, 240, 'admin.navigation.path');
            if (!preg_match(self::NAV_PATH_PATTERN, $path)) {
                throw new InvalidArgumentException('admin.navigation.path must be a safe relative path');
            }

            $item = [
                'id' => $id,
                'title' => $title,
                'path' => $path,
            ];

            if (array_key_exists('icon', $entry)) {
                if (!is_string($entry['icon']) || trim($entry['icon']) === '') {
                    throw new InvalidArgumentException('admin.navigation.icon must be a non-empty string');
                }
                self::assertLength($entry['icon'], 1, 80, 'admin.navigation.icon');
                $item['icon'] = $entry['icon'];
            }

            if (array_key_exists('order', $entry)) {
                if (!is_int($entry['order']) || $entry['order'] < -100000 || $entry['order'] > 100000) {
                    throw new InvalidArgumentException('admin.navigation.order is invalid');
                }
                $item['order'] = $entry['order'];
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    private static function assertModuleId(string $id): void
    {
        self::assertLength($id, 2, 64, 'module ID');
        if (!preg_match(self::ID_PATTERN, $id)) {
            throw new InvalidArgumentException('module ID is invalid');
        }
    }

    private static function requireArray(array $source, string $key): array
    {
        if (!array_key_exists($key, $source) || !is_array($source[$key]) || array_is_list($source[$key])) {
            throw new InvalidArgumentException("{$key} must be an object");
        }

        return $source[$key];
    }

    private static function requireString(array $source, string $key, string $label): string
    {
        if (!array_key_exists($key, $source) || !is_string($source[$key]) || trim($source[$key]) === '') {
            throw new InvalidArgumentException("{$label} must be a non-empty string");
        }

        return trim($source[$key]);
    }

    private static function optionalString(array $source, string $key, int $max, string $label): ?string
    {
        if (!array_key_exists($key, $source)) {
            return null;
        }
        if (!is_string($source[$key])) {
            throw new InvalidArgumentException("{$label} must be a string");
        }

        $value = trim($source[$key]);
        self::assertLength($value, 0, $max, $label);

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    private static function assertAllowedKeys(array $value, array $allowed, string $label): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                "{$label} contains unsupported field: " . (string) reset($unknown)
            );
        }
    }

    private static function assertLength(string $value, int $min, int $max, string $label): void
    {
        $length = strlen($value);
        if ($length < $min || $length > $max) {
            throw new InvalidArgumentException("{$label} length is invalid");
        }
    }
}
