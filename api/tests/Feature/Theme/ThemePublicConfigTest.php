<?php

namespace Tests\Feature\Theme;

use App\Services\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemePublicConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_frontend_receives_only_native_theme_configuration(): void
    {
        $service = app(ThemeService::class);
        $service->updateConfig('TXBoard', [
            'theme_color' => 'blue',
            'background_url' => 'https://example.test/login.jpg',
        ]);
        $response = $this->getJson('/txapi/public/site-config');
        $response->assertOk();
        $response->assertJsonPath('data.frontend_theme', 'TXBoard');
        $response->assertJsonPath('data.theme_config.theme_color', 'blue');
        $response->assertJsonPath('data.theme_config.background_url', 'https://example.test/login.jpg');

        // Undeclared private fields must not be exposed as public settings.
        $stored = $service->getConfig('TXBoard');
        admin_setting(['theme_TXBoard' => array_merge($stored, ['smtp_password' => 'never-public'])]);
        $this->getJson('/txapi/public/site-config')
            ->assertJsonMissingPath('data.theme_config.smtp_password');

        $service->updateConfig('TXBoard', ['theme_color' => 'darkblue']);
        $this->getJson('/txapi/public/site-config')
            ->assertJsonPath('data.theme_config.theme_color', 'darkblue');
    }
}
