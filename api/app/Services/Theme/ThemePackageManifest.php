<?php

namespace App\Services\Theme;

use InvalidArgumentException;

final readonly class ThemePackageManifest
{
    private const VERSION_PATTERN = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/';

    public function __construct(
        public string $name,
        public string $version,
        public string $txboardCompatibility,
        public array $configs,
        public ?string $description = null,
        public ?string $author = null,
    ) {
    }

    public static function fromArray(array $config): self
    {
        $name = self::requiredString($config, 'name', 'theme name');
        self::assertLength($name, 1, 120, 'theme name');
        if (
            str_contains($name, chr(0))
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || in_array($name, ['.', '..'], true)
        ) {
            throw new InvalidArgumentException('theme name must be a safe directory name');
        }

        $version = self::requiredString($config, 'version', 'theme version');
        if (!preg_match(self::VERSION_PATTERN, $version)) {
            throw new InvalidArgumentException('theme version must be valid SemVer');
        }

        $description = self::optionalString($config, 'description', 1000, 'theme description');
        $author = self::optionalString($config, 'author', 120, 'theme author');

        $compatibility = $config['compatibility'] ?? ['txboard' => '*'];
        if (!is_array($compatibility) || array_is_list($compatibility)) {
            throw new InvalidArgumentException('theme compatibility must be an object');
        }
        $txboardCompatibility = trim((string) ($compatibility['txboard'] ?? '*'));
        if ($txboardCompatibility === '') {
            throw new InvalidArgumentException('theme compatibility.txboard must be non-empty');
        }
        self::assertLength($txboardCompatibility, 1, 120, 'theme compatibility.txboard');

        $configs = $config['configs'] ?? [];
        if (!is_array($configs) || !array_is_list($configs)) {
            throw new InvalidArgumentException('theme configs must be an array');
        }

        $seenFields = [];
        foreach ($configs as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new InvalidArgumentException('theme config entries must be objects');
            }
            $field = self::requiredString($entry, 'field_name', 'theme config field_name');
            self::assertLength($field, 1, 120, 'theme config field_name');
            if (isset($seenFields[$field])) {
                throw new InvalidArgumentException('theme config field_name values must be unique');
            }
            $seenFields[$field] = true;
        }

        return new self(
            name: $name,
            version: $version,
            txboardCompatibility: $txboardCompatibility,
            configs: $configs,
            description: $description,
            author: $author,
        );
    }

    private static function requiredString(array $source, string $key, string $label): string
    {
        if (!is_string($source[$key] ?? null) || trim($source[$key]) === '') {
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

    private static function assertLength(string $value, int $min, int $max, string $label): void
    {
        $length = strlen($value);
        if ($length < $min || $length > $max) {
            throw new InvalidArgumentException("{$label} length is invalid");
        }
    }
}
