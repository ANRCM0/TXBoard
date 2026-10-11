<?php

namespace Tests\Unit\Services\Theme;

use App\Services\Theme\ThemePackage;
use App\Services\Theme\ThemeV2Package;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ThemeV2PackageTest extends TestCase
{
    /** @return array<string, mixed> */
    private function manifest(string $js = 'console.log("TXBoard theme")'): array
    {
        return [
            'format' => 2,
            'name' => 'test-theme',
            'title' => 'Test theme',
            'version' => '1.0.0',
            'entry' => ['type' => 'spa', 'module' => 'dist/index.js'],
            'engines' => ['txboard' => '>=3.6', 'gateway' => '>=1 <2', 'contract' => '1.x'],
            'gateway' => [
                'contract' => '1.x',
                'sdk' => ['package' => '@txboard/theme-sdk', 'version' => '>=0.1.0 <1.0.0'],
                'capabilities' => ['bootstrap', 'plans', 'auth.login'],
            ],
            'assets' => [
                ['path' => 'dist/index.js', 'integrity' => 'sha256-'.base64_encode(hash('sha256', $js, true))],
            ],
            'dependencies' => ['runtime' => []],
            'security' => ['csp' => 'strict', 'remoteCode' => false],
        ];
    }

    private function archive(array $manifest, array $files = [], ?string $prefix = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'txboard-v2-');
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE));
        $root = $prefix === null ? '' : $prefix.'/';
        self::assertTrue($zip->addFromString(
            $root.'txboard-theme.json',
            json_encode($manifest, JSON_THROW_ON_ERROR)
        ));
        foreach ($files as $name => $data) {
            self::assertTrue($zip->addFromString($root.$name, $data));
        }
        self::assertTrue($zip->close());
        $reader = new ZipArchive;
        self::assertTrue($reader->open($path));
        return [$path, $reader];
    }

    private function check(array $manifest, array $files = [], string $tx = '3.6.0',
        string $gw = '1.0.0', string $sdk = '0.1.0', ?string $prefix = null): array
    {
        [$path, $zip] = $this->archive($manifest, $files, $prefix);
        try {
            return (new ThemeV2Package(new ThemePackage))->validate($zip, $tx, $gw, $sdk);
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_valid_spa_manifest_is_accepted_without_loading_code(): void
    {
        $js = 'console.log("TXBoard theme")';
        $validated = $this->check($this->manifest($js), ['dist/index.js' => $js], prefix: 'test-theme');
        self::assertSame('test-theme', $validated['name']);
        self::assertSame(2, $validated['format']);
    }

    public function test_modified_asset_is_rejected_by_sri(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('integrity mismatch');
        $this->check($this->manifest(), ['dist/index.js' => 'malicious replacement']);
    }

    public function test_any_undeclared_asset_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('undeclared asset');
        $this->check($this->manifest(), [
            'dist/index.js' => 'console.log("TXBoard theme")',
            'dist/hidden.js' => 'evil()',
        ]);
    }

    public function test_legacy_files_cannot_be_smuggled_into_v2_package(): void
    {
        foreach (['config.json', 'dashboard.blade.php', '.env', 'dist/shell.php', 'assets/private.pem'] as $path) {
            try {
                $this->check($this->manifest(), [
                    'dist/index.js' => 'console.log("TXBoard theme")', $path => 'secret',
                ]);
                self::fail("Did not reject forbidden file {$path}");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('forbidden', $e->getMessage());
            }
        }
    }

    public function test_ssr_remains_disabled_until_isolated_runtime_is_approved(): void
    {
        $manifest = $this->manifest();
        $manifest['entry']['type'] = 'ssr';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SSR is not enabled');
        $this->check($manifest, ['dist/index.js' => 'console.log("TXBoard theme")']);
    }

    public function test_txboard_gateway_and_sdk_mismatch_fail_closed(): void
    {
        foreach ([
            ['3.5.9','1.0.0','0.1.0'],
            ['3.6.0','2.0.0','0.1.0'],
            ['3.6.0','1.0.0','1.0.0'],
        ] as [$tx, $gw, $sdk]) {
            try {
                $this->check($this->manifest(), ['dist/index.js' => 'console.log("TXBoard theme")'],
                    $tx, $gw, $sdk);
                self::fail('Incompatible runtime version was accepted');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('Incompatible', $e->getMessage());
            }
        }
    }

    public function test_version_cannot_be_guessed_from_untrusted_manifest(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid TXBoard deployed version');
        $this->check($this->manifest(), ['dist/index.js' => 'console.log("TXBoard theme")'],
            tx: 'unknown');
    }

    public function test_unknown_manifest_field_is_rejected(): void
    {
        $manifest = $this->manifest();
        $manifest['grantAdmin'] = true;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown Theme v2 manifest field');
        $this->check($manifest, ['dist/index.js' => 'console.log("TXBoard theme")']);
    }

    public function test_declared_capabilities_are_hints_not_permissions(): void
    {
        $manifest = $this->manifest();
        $manifest['gateway']['capabilities'][] = 'orders.create';
        $validated = $this->check($manifest, ['dist/index.js' => 'console.log("TXBoard theme")']);
        self::assertContains('orders.create', $validated['gateway']['capabilities']);
        // No routes, authorization or server settings are changed by validate().
    }

    public function test_absolute_paths_and_traversal_are_rejected_by_shared_v1_zip_guard(): void
    {
        foreach (['../secret.php', '/etc/.env', 'C:/private.pem'] as $name) {
            [$path, $zip] = $this->archive($this->manifest(), [
                'dist/index.js' => 'console.log("TXBoard theme")', $name => 'oops',
            ]);
            try {
                try {
                    (new ThemeV2Package(new ThemePackage))->validate($zip, '3.6.0','1.0.0','0.1.0');
                    self::fail("Unsafe ZIP entry {$name} was accepted");
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('path', $e->getMessage());
                }
            } finally {
                $zip->close();
                unlink($path);
            }
        }
    }

    public function test_hidden_server_directive_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('forbidden hidden file');
        $this->check($this->manifest(), [
            'dist/index.js' => 'console.log("TXBoard theme")',
            'assets/.htaccess' => 'AddHandler application/x-httpd-php .txt',
        ]);
    }

    public function test_manifest_cannot_expand_public_config_authority(): void
    {
        $manifest = $this->manifest();
        $manifest['config'] = ['public' => ['smtp_password', 'admin_token']];
        $validated = $this->check($manifest, ['dist/index.js' => 'console.log("TXBoard theme")']);
        // Public candidates must be intersected with a server-managed allowlist by
        // the future installer/config adapter, never blindly exposed.
        self::assertSame(['smtp_password', 'admin_token'], $validated['config']['public']);
    }
}
