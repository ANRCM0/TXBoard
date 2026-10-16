<?php

namespace Tests\Feature\Theme;

use App\Services\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ThemeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_builtin_inventory_and_default_resolution_are_read_only(): void
    {
        $service = app(ThemeService::class);
        $this->assertSame('TXBoard', $service->getActiveTheme());
        $this->assertSame('builtin-spa', $service->getList()['TXBoard']['runtime']);
        $this->assertNull(admin_setting('frontend_theme'));
        $this->assertNull(admin_setting('theme_TXBoard'));
        $service->getConfig('TXBoard');
        $this->assertNull(admin_setting('theme_TXBoard'));
        $this->get('/')->assertOk()->assertSee('id="app"', false);
    }

    public function test_old_blade_directory_is_not_a_theme_and_is_never_executed(): void
    {
        $path = base_path('storage/theme/retired-blade-test');
        File::ensureDirectoryExists($path);
        File::put($path.'/config.json', '{"name":"retired-blade-test","version":"1.0.0"}');
        File::put($path.'/dashboard.blade.php', '<?php throw new \RuntimeException("must not execute");');
        try {
            $service = app(ThemeService::class);
            $this->assertFalse($service->exists('retired-blade-test'));
            $this->assertArrayNotHasKey('retired-blade-test', $service->getList());
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Theme not found');
            $service->switch('retired-blade-test');
        } finally {
            File::deleteDirectory($path);
        }
    }

    public function test_missing_setting_resolves_default_without_mutating_persisted_value(): void
    {
        admin_setting(['frontend_theme' => 'missing-theme']);
        $this->assertSame('TXBoard', app(ThemeService::class)->getActiveTheme());
        $this->assertSame('missing-theme', admin_setting('frontend_theme'));
    }

    public function test_system_theme_and_unsafe_id_cannot_be_deleted(): void
    {
        foreach (['TXBoard' => 'System theme cannot be deleted', '../TXBoard' => 'Invalid theme name'] as $name => $message) {
            try {
                app(ThemeService::class)->delete($name);
                $this->fail('Protected theme reference accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }
}
