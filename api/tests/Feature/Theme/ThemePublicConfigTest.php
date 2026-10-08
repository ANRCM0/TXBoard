<?php

namespace Tests\Feature\Theme;

use App\Services\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemePublicConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_frontend_receives_selected_theme_configuration_not_legacy_global_appearance(): void
    {
        $service = app(ThemeService::class);
        $service->updateConfig('TXBoard', [
            'theme_color' => 'blue',
            'background_url' => 'https://example.test/login.jpg',
        ]);
        admin_setting([
            'frontend_theme_color' => 'black',
            'frontend_background_url' => 'https://example.test/old.jpg',
        ]);

        $response = $this->getJson('/api/v1/guest/comm/config');
        $response->assertOk();
        $response->assertJsonPath('data.frontend_theme', 'TXBoard');
        $response->assertJsonPath('data.theme_config.theme_color', 'blue');
        $response->assertJsonPath('data.theme_config.background_url', 'https://example.test/login.jpg');
        $response->assertJsonMissingPath('data.frontend_theme_color');
        $response->assertJsonMissingPath('data.frontend_background_url');

        // Legacy and undeclared private fields are not public theme settings.
        $stored = $service->getConfig('TXBoard');
        admin_setting(['theme_TXBoard' => array_merge($stored, ['smtp_password' => 'never-public'])]);
        $this->getJson('/api/v1/guest/comm/config')
            ->assertJsonMissingPath('data.theme_config.smtp_password');

        $service->updateConfig('TXBoard', ['theme_color' => 'darkblue']);
        $this->getJson('/api/v1/guest/comm/config')
            ->assertJsonPath('data.theme_config.theme_color', 'darkblue');
    }
}
