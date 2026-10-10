<?php

namespace Tests\Unit\Services\Plugin;

use App\Services\Plugin\PluginPackage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PluginPackageTest extends TestCase
{
    private PluginPackage $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->package = new PluginPackage();
    }

    public function test_native_plugin_without_optional_admin_app_remains_valid(): void
    {
        $this->assertTrue($this->package->validateManifestExtension([
            'admin_menus' => [['path' => 'dashboard', 'component' => 'plugin.dashboard']],
        ]));
    }

    public function test_v1_plugin_admin_app_is_valid(): void
    {
        $config = [
            'package' => ['schema' => 1],
            'admin_menus' => [
                ['path' => 'dashboard', 'app' => 'admin/index.html#/dashboard'],
            ],
        ];

        $this->assertTrue($this->package->validateManifestExtension($config));
        $this->assertTrue($this->package->isSafeAdminAppReference('admin/index.html#/dashboard'));
    }

    public function test_admin_app_requires_package_v1(): void
    {
        $this->assertFalse($this->package->validateManifestExtension([
            'admin_menus' => [['path' => 'dashboard', 'app' => 'admin/index.html']],
        ]));
    }

    public function test_external_absolute_and_traversal_entries_are_rejected(): void
    {
        foreach ([
            'https://example.com/index.html',
            '/admin/index.html',
            'admin/../index.html',
            'admin\\index.html',
            'admin/app.js',
        ] as $reference) {
            $this->assertFalse(
                $this->package->isSafeAdminAppReference($reference),
                "Reference should be rejected: {$reference}"
            );
        }
    }

    public function test_declared_admin_entry_must_exist_in_admin_dist(): void
    {
        $root = sys_get_temp_dir() . '/txboard-plugin-' . bin2hex(random_bytes(4));
        mkdir($root . '/admin/dist', 0777, true);
        file_put_contents($root . '/admin/dist/index.html', '<!doctype html>');

        $config = [
            'package' => ['schema' => 1],
            'admin_menus' => [
                ['path' => 'dashboard', 'app' => 'admin/index.html#/dashboard'],
            ],
        ];

        $this->package->assertDeclaredAdminAppsExist($root, $config);
        $this->assertTrue(true);

        unlink($root . '/admin/dist/index.html');
        rmdir($root . '/admin/dist');
        rmdir($root);
    }

    public function test_missing_declared_admin_entry_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->package->assertDeclaredAdminAppsExist(sys_get_temp_dir(), [
            'package' => ['schema' => 1],
            'admin_menus' => [
                ['path' => 'dashboard', 'app' => 'admin/index.html'],
            ],
        ]);
    }
}
