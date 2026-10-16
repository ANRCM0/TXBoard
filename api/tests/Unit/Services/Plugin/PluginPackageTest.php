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

    public function test_current_plugin_requires_explicit_package_schema(): void
    {
        $this->assertFalse($this->package->validateManifestExtension([]));
        $this->assertTrue($this->package->validateManifestExtension(['package' => ['schema' => 1]]));
        $this->assertFalse($this->package->validateManifestExtension(['package' => ['schema' => '1']]));
    }

    public function test_v1_plugin_admin_app_is_valid(): void
    {
        $config = [
            'package' => ['schema' => 1],
            'admin_menus' => [
                ['id' => 'dashboard', 'title' => 'Dashboard', 'path' => 'dashboard', 'app' => 'admin/index.html#/dashboard'],
            ],
        ];

        $this->assertTrue($this->package->validateManifestExtension($config));
        $this->assertTrue($this->package->isSafeAdminAppReference('admin/index.html#/dashboard'));
    }

    public function test_admin_app_requires_package_v1(): void
    {
        $this->assertFalse($this->package->validateManifestExtension([
            'admin_menus' => [['id' => 'dashboard', 'title' => 'Dashboard', 'path' => 'dashboard', 'app' => 'admin/index.html']],
        ]));
    }

    public function test_old_menu_aliases_and_missing_explicit_identity_are_rejected(): void
    {
        $valid = ['id' => 'dashboard', 'title' => 'Dashboard', 'path' => 'dashboard', 'app' => 'admin/index.html'];
        foreach (['label', 'component', 'url', 'embed', 'renderer'] as $alias) {
            $this->assertFalse($this->package->validateManifestExtension([
                'package' => ['schema' => 1], 'admin_menus' => [array_merge($valid, [$alias => 'old'])],
            ]));
        }
        foreach (['id', 'title', 'path', 'app'] as $field) {
            $menu = $valid;
            unset($menu[$field]);
            $this->assertFalse($this->package->validateManifestExtension([
                'package' => ['schema' => 1], 'admin_menus' => [$menu],
            ]));
        }
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
                ['id' => 'dashboard', 'title' => 'Dashboard', 'path' => 'dashboard', 'app' => 'admin/index.html#/dashboard'],
            ],
        ];

        $this->package->assertDeclaredAdminAppsExist($root, $config);
        $this->assertTrue(true);

        unlink($root . '/admin/dist/index.html');
        rmdir($root . '/admin/dist');
        rmdir($root . '/admin');
        rmdir($root);
    }

    public function test_missing_declared_admin_entry_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->package->assertDeclaredAdminAppsExist(sys_get_temp_dir(), [
            'package' => ['schema' => 1],
            'admin_menus' => [
                ['id' => 'dashboard', 'title' => 'Dashboard', 'path' => 'dashboard', 'app' => 'admin/index.html'],
            ],
        ]);
    }
}
