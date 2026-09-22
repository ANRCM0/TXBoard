<?php

namespace Tests\Unit\Services\Module;

use App\Services\Module\ModuleCapability;
use App\Services\Module\ModuleDescriptor;
use App\Services\Module\ModuleHealth;
use App\Services\Module\ModuleManifest;
use App\Services\Module\ModuleSource;
use App\Services\Module\ModuleType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ModuleManifestTest extends TestCase
{
    public function test_v1_example_manifest_parses_and_round_trips(): void
    {
        $manifest = ModuleManifest::fromArray($this->exampleManifest());

        $this->assertSame('access_audit', $manifest->id);
        $this->assertSame('Access Audit', $manifest->name);
        $this->assertSame('1.2.0', $manifest->version);
        $this->assertSame(ModuleType::PLUGIN, $manifest->type);
        $this->assertSame('>=1.0.0', $manifest->txboardCompatibility);
        $this->assertTrue($manifest->hasCapability(ModuleCapability::ADMIN_APP));
        $this->assertFalse($manifest->hasCapability(ModuleCapability::THEME));

        $this->assertSame($this->exampleManifest(), $manifest->toArray());
    }

    public function test_schema_capability_and_type_vocabularies_match_php_contract(): void
    {
        $schema = $this->schema();

        $schemaCapabilities = $schema['properties']['capabilities']['items']['enum'];
        $this->assertSame(ModuleCapability::all(), $schemaCapabilities);

        $schemaTypes = $schema['properties']['module']['properties']['type']['enum'];
        $phpTypes = array_map(
            static fn(ModuleType $type) => $type->value,
            ModuleType::cases()
        );
        $this->assertSame($phpTypes, $schemaTypes);
        $this->assertSame(ModuleManifest::SCHEMA_VERSION, $schema['properties']['schema']['const']);
    }

    public function test_runtime_descriptor_keeps_runtime_state_outside_manifest(): void
    {
        $manifest = ModuleManifest::fromArray($this->exampleManifest());

        $descriptor = new ModuleDescriptor(
            manifest: $manifest,
            source: ModuleSource::USER,
            installed: true,
            enabled: true,
            active: null,
            health: ModuleHealth::HEALTHY,
        );

        $this->assertSame([
            'id' => 'access_audit',
            'name' => 'Access Audit',
            'version' => '1.2.0',
            'type' => 'plugin',
            'source' => 'user',
            'installed' => true,
            'enabled' => true,
            'active' => null,
            'health' => 'healthy',
            'capabilities' => [
                'admin.menu',
                'admin.app',
                'api.route',
                'database.migration',
            ],
            'compatibility' => [
                'txboard' => '>=1.0.0',
            ],
        ], $descriptor->toArray());

        $this->assertArrayNotHasKey('enabled', $manifest->toArray());
        $this->assertArrayNotHasKey('health', $manifest->toArray());
        $this->assertArrayNotHasKey('source', $manifest->toArray());
    }

    #[DataProvider('invalidManifestProvider')]
    public function test_invalid_manifests_are_rejected(callable $mutate): void
    {
        $manifest = $this->exampleManifest();
        $mutate($manifest);

        $this->expectException(InvalidArgumentException::class);
        ModuleManifest::fromArray($manifest);
    }

    public static function invalidManifestProvider(): array
    {
        return [
            'unsupported schema' => [
                static function (array &$manifest): void {
                    $manifest['schema'] = 2;
                },
            ],
            'invalid module id' => [
                static function (array &$manifest): void {
                    $manifest['module']['id'] = 'Access Audit';
                },
            ],
            'invalid semver' => [
                static function (array &$manifest): void {
                    $manifest['module']['version'] = '1.2';
                },
            ],
            'unsupported type' => [
                static function (array &$manifest): void {
                    $manifest['module']['type'] = 'widget';
                },
            ],
            'unknown capability' => [
                static function (array &$manifest): void {
                    $manifest['capabilities'][] = 'database.raw';
                },
            ],
            'duplicate capability' => [
                static function (array &$manifest): void {
                    $manifest['capabilities'][] = 'admin.app';
                },
            ],
            'invalid dependency id' => [
                static function (array &$manifest): void {
                    $manifest['dependencies'] = ['Other Module' => '>=1.0.0'];
                },
            ],
            'empty dependency constraint' => [
                static function (array &$manifest): void {
                    $manifest['dependencies'] = ['base_reporting' => ''];
                },
            ],
            'absolute navigation path' => [
                static function (array &$manifest): void {
                    $manifest['admin']['navigation'][0]['path'] = '/audit';
                },
            ],
            'navigation traversal' => [
                static function (array &$manifest): void {
                    $manifest['admin']['navigation'][0]['path'] = 'audit/../settings';
                },
            ],
            'duplicate navigation id' => [
                static function (array &$manifest): void {
                    $manifest['admin']['navigation'][] = [
                        'id' => 'audit',
                        'title' => 'Other',
                        'path' => 'other',
                    ];
                },
            ],
            'runtime state in package declaration' => [
                static function (array &$manifest): void {
                    $manifest['enabled'] = true;
                },
            ],
            'unknown module field' => [
                static function (array &$manifest): void {
                    $manifest['module']['vendor_url'] = 'https://example.com';
                },
            ],
        ];
    }

    public function test_empty_optional_sections_are_valid(): void
    {
        $manifest = $this->exampleManifest();
        unset($manifest['dependencies'], $manifest['admin']);

        $parsed = ModuleManifest::fromArray($manifest);

        $this->assertSame([], $parsed->dependencies);
        $this->assertSame([], $parsed->adminNavigation);
    }

    public function test_prerelease_and_build_metadata_semver_is_valid(): void
    {
        $manifest = $this->exampleManifest();
        $manifest['module']['version'] = '2.0.0-rc.1+build.7';

        $parsed = ModuleManifest::fromArray($manifest);

        $this->assertSame('2.0.0-rc.1+build.7', $parsed->version);
    }

    private function exampleManifest(): array
    {
        $path = $this->repositoryRoot() . '/contracts/module-package/examples/plugin.json';
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function schema(): array
    {
        $path = $this->repositoryRoot() . '/contracts/module-package/schema-v1.json';
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 5);
    }
}
