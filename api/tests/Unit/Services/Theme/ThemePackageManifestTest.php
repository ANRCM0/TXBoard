<?php

namespace Tests\Unit\Services\Theme;

use App\Services\Theme\ThemePackageManifest;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ThemePackageManifestTest extends TestCase
{
    public function test_v1_example_parses_with_compatibility_and_config_schema(): void
    {
        $config = $this->example();
        $manifest = ThemePackageManifest::fromArray($config);

        $this->assertSame('ExampleTheme', $manifest->name);
        $this->assertSame('1.2.0', $manifest->version);
        $this->assertSame('>=1.0.0', $manifest->txboardCompatibility);
        $this->assertSame('TXBoard', $manifest->author);
        $this->assertSame('theme_color', $manifest->configs[0]['field_name']);
    }

    public function test_legacy_theme_without_explicit_compatibility_defaults_to_wildcard(): void
    {
        $manifest = ThemePackageManifest::fromArray([
            'name' => 'Legacy Theme',
            'version' => '1.0.0',
            'configs' => [],
        ]);

        $this->assertSame('*', $manifest->txboardCompatibility);
    }

    #[DataProvider('invalidProvider')]
    public function test_invalid_v1_metadata_is_rejected(callable $mutate): void
    {
        $config = $this->example();
        $mutate($config);

        $this->expectException(InvalidArgumentException::class);
        ThemePackageManifest::fromArray($config);
    }

    public static function invalidProvider(): array
    {
        return [
            'path traversal name' => [
                static function (array &$config): void {
                    $config['name'] = '../theme';
                },
            ],
            'absolute-like name' => [
                static function (array &$config): void {
                    $config['name'] = '/theme';
                },
            ],
            'invalid semver' => [
                static function (array &$config): void {
                    $config['version'] = '1.2';
                },
            ],
            'empty compatibility' => [
                static function (array &$config): void {
                    $config['compatibility']['txboard'] = '';
                },
            ],
            'duplicate config field' => [
                static function (array &$config): void {
                    $config['configs'][] = $config['configs'][0];
                },
            ],
        ];
    }

    public function test_schema_and_example_keep_required_v1_fields_in_sync(): void
    {
        $schema = $this->schema();

        $this->assertSame(['name', 'version'], $schema['required']);
        $this->assertArrayHasKey('compatibility', $schema['properties']);
        $this->assertArrayHasKey('configs', $schema['properties']);

        ThemePackageManifest::fromArray($this->example());
        $this->assertTrue(true);
    }

    private function example(): array
    {
        $path = $this->repositoryRoot() . '/contracts/theme-package/examples/theme.json';
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function schema(): array
    {
        $path = $this->repositoryRoot() . '/contracts/theme-package/schema-v1.json';
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 5);
    }
}
