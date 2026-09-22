<?php

namespace Tests\Feature\Theme;

use App\Services\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ThemeServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $createdThemes = [];

    protected function tearDown(): void
    {
        foreach ($this->createdThemes as $theme) {
            File::deleteDirectory(base_path('storage/theme/' . $theme));
            File::deleteDirectory(public_path('theme/' . $theme));
        }

        parent::tearDown();
    }

    public function test_default_theme_resolves_without_mutating_state(): void
    {
        $service = app(ThemeService::class);

        $this->assertSame('TXBoard', $service->getActiveTheme());
        $this->assertNull(admin_setting('frontend_theme'));
    }

    public function test_valid_legacy_theme_is_read_only_compatibility_fallback(): void
    {
        $this->createTheme('LegacyTheme');
        admin_setting(['current_theme' => 'LegacyTheme']);

        $service = app(ThemeService::class);

        $this->assertSame('LegacyTheme', $service->getActiveTheme());
        $this->assertNull(admin_setting('frontend_theme'));
        $this->assertSame('LegacyTheme', admin_setting('current_theme'));
    }

    public function test_invalid_canonical_theme_does_not_fall_back_to_legacy_state(): void
    {
        $this->createTheme('LegacyTheme');
        admin_setting([
            'frontend_theme' => 'MissingTheme',
            'current_theme' => 'LegacyTheme',
        ]);

        $service = app(ThemeService::class);

        $this->assertSame('TXBoard', $service->getActiveTheme());
        $this->assertSame('MissingTheme', admin_setting('frontend_theme'));
        $this->assertSame('LegacyTheme', admin_setting('current_theme'));
    }

    public function test_switch_writes_only_canonical_active_theme_state(): void
    {
        $this->createTheme('CustomTheme');
        admin_setting(['current_theme' => 'HistoricalTheme']);

        $service = app(ThemeService::class);
        $service->switch('CustomTheme');

        $this->assertSame('CustomTheme', $service->getActiveTheme());
        $this->assertSame('CustomTheme', admin_setting('frontend_theme'));
        $this->assertSame('HistoricalTheme', admin_setting('current_theme'));
    }

    public function test_unsafe_theme_reference_never_resolves_outside_theme_roots(): void
    {
        $service = app(ThemeService::class);

        $this->assertNull($service->getThemePath('../TXBoard'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid theme name');
        $service->delete('../TXBoard');
    }

    public function test_system_theme_inventory_wins_exact_legacy_user_name_collision(): void
    {
        $this->createTheme('TXBoard');

        $theme = app(ThemeService::class)->getList()['TXBoard'];

        $this->assertTrue($theme['is_system']);
        $this->assertFalse($theme['can_delete']);
        $this->assertSame('TXBoard default theme', $theme['description']);
    }

    public function test_switching_back_to_default_updates_only_frontend_theme(): void
    {
        $this->createTheme('CustomTheme');
        admin_setting([
            'frontend_theme' => 'CustomTheme',
            'current_theme' => 'HistoricalTheme',
        ]);

        $service = app(ThemeService::class);
        $service->switch('TXBoard');

        $this->assertSame('TXBoard', $service->getActiveTheme());
        $this->assertSame('TXBoard', admin_setting('frontend_theme'));
        $this->assertSame('HistoricalTheme', admin_setting('current_theme'));
    }

    public function test_active_user_theme_cannot_be_deleted(): void
    {
        $this->createTheme('CustomTheme');
        admin_setting(['frontend_theme' => 'CustomTheme']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Current theme cannot be deleted');

        app(ThemeService::class)->delete('CustomTheme');
    }

    public function test_system_theme_cannot_be_deleted(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('System theme cannot be deleted');

        app(ThemeService::class)->delete('TXBoard');
    }

    private function createTheme(string $name): void
    {
        $path = base_path('storage/theme/' . $name);
        File::ensureDirectoryExists($path);

        File::put($path . '/config.json', json_encode([
            'name' => $name,
            'version' => '1.0.0',
            'compatibility' => ['txboard' => '*'],
            'configs' => [],
        ], JSON_THROW_ON_ERROR));
        File::put($path . '/dashboard.blade.php', '<!doctype html>');

        $this->createdThemes[] = $name;
    }
}
