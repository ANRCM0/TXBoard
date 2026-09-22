<?php

namespace Tests\Unit\Services\Theme;

use App\Services\Theme\ThemePackage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ThemePackageTest extends TestCase
{
    private ThemePackage $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->package = new ThemePackage();
    }

    public function test_safe_root_package_is_accepted(): void
    {
        $path = $this->zip([
            'config.json' => '{"name":"SafeTheme","version":"1.0.0","configs":[]}',
            'dashboard.blade.php' => '<html></html>',
            'assets/app.js' => 'console.log("ok");',
        ]);

        $zip = $this->open($path);
        try {
            $this->package->assertSafeArchive($zip);
            $this->assertSame('config.json', $this->package->configEntry($zip));
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_one_top_level_directory_is_supported(): void
    {
        $path = $this->zip([
            'Theme/config.json' => '{"name":"SafeTheme","version":"1.0.0","configs":[]}',
            'Theme/dashboard.blade.php' => '<html></html>',
        ]);

        $zip = $this->open($path);
        try {
            $this->package->assertSafeArchive($zip);
            $this->assertSame('Theme/config.json', $this->package->configEntry($zip));
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_path_traversal_entry_is_rejected_before_extraction(): void
    {
        $path = $this->zip([
            '../escape.php' => 'unsafe',
            'config.json' => '{"name":"SafeTheme","version":"1.0.0","configs":[]}',
        ]);

        $zip = $this->open($path);
        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('path traversal');
            $this->package->assertSafeArchive($zip);
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_absolute_and_windows_paths_are_rejected(): void
    {
        foreach ([
            '/absolute/config.json',
            'C:/theme/config.json',
            'Theme\\config.json',
        ] as $entry) {
            $path = $this->zip([$entry => '{}']);
            $zip = $this->open($path);

            try {
                try {
                    $this->package->assertSafeArchive($zip);
                    $this->fail("Unsafe archive path should be rejected: {$entry}");
                } catch (InvalidArgumentException) {
                    $this->assertTrue(true);
                }
            } finally {
                $zip->close();
                unlink($path);
            }
        }
    }

    public function test_symbolic_link_entry_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'txboard-theme-');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $this->assertTrue($zip->addFromString('link', 'target'));
        $this->assertTrue($zip->setExternalAttributesName(
            'link',
            ZipArchive::OPSYS_UNIX,
            0120777 << 16,
        ));
        $zip->close();

        $zip = $this->open($path);
        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('symbolic links');
            $this->package->assertSafeArchive($zip);
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_multiple_config_files_are_rejected_as_ambiguous(): void
    {
        $path = $this->zip([
            'config.json' => '{}',
            'Nested/config.json' => '{}',
        ]);

        $zip = $this->open($path);
        try {
            $this->expectException(InvalidArgumentException::class);
            $this->package->configEntry($zip);
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_deeply_nested_config_is_rejected(): void
    {
        $path = $this->zip([
            'Outer/Inner/config.json' => '{}',
        ]);

        $zip = $this->open($path);
        try {
            $this->expectException(InvalidArgumentException::class);
            $this->package->configEntry($zip);
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'txboard-theme-');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        foreach ($entries as $name => $content) {
            $this->assertTrue($zip->addFromString($name, $content));
        }

        $zip->close();
        return $path;
    }

    private function open(string $path): ZipArchive
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path));

        return $zip;
    }
}
