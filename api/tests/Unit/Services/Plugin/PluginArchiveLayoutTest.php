<?php

namespace Tests\Unit\Services\Plugin;

use App\Services\Plugin\PluginPackage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class PluginArchiveLayoutTest extends TestCase
{
    public function test_valid_single_root_package_has_runtime_and_manifest(): void
    {
        $this->withZip([
            'Extension/config.json' => '{"code":"extension"}',
            'Extension/Plugin.php' => '<?php // runtime',
        ], function (ZipArchive $zip): void {
            $package = new PluginPackage();
            $package->assertSafeArchive($zip);
            $package->assertInstallableArchive($zip);
            $this->assertTrue(true);
        });
    }

    public function test_multiple_candidate_manifests_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->withZip([
            'Plugin.php' => '<?php',
            'config.json' => '{}',
            'Other/config.json' => '{}',
        ], static function (ZipArchive $zip): void {
            (new PluginPackage())->assertInstallableArchive($zip);
        });
    }

    public function test_missing_runtime_file_is_rejected_before_extract(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->withZip(['config.json' => '{}'], static function (ZipArchive $zip): void {
            (new PluginPackage())->assertInstallableArchive($zip);
        });
    }

    public function test_casefold_collisions_cannot_overwrite_package_files(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->withZip([
            'Plugin.php' => '<?php',
            'config.json' => '{}',
            'CONFIG.json' => '{}',
        ], static function (ZipArchive $zip): void {
            (new PluginPackage())->assertSafeArchive($zip);
        });
    }

    private function withZip(array $files, callable $assertion): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('zip extension is unavailable');
        }
        $path = tempnam(sys_get_temp_dir(), 'txboard-plugin-archive-');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        try {
            $this->assertTrue($zip->open($path) === true);
            $assertion($zip);
        } finally {
            $zip->close();
            @unlink($path);
        }
    }
}
