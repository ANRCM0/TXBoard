<?php

namespace Tests\Feature\Theme;

use App\Services\Theme\ThemeV2Runtime;
use App\Services\ThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

final class ThemeV2RuntimeTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'runtime-theme-test';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('theme_v2.txboard_version', '3.6.0');
        config()->set('theme_v2.browser_version', '1.0.0');
        config()->set('theme_v2.sdk_version', '0.2.0');
        $this->cleanupThemeFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanupThemeFiles();
        parent::tearDown();
    }

    private function cleanupThemeFiles(): void
    {
        File::deleteDirectory(base_path('storage/theme/'.self::NAME));
        File::deleteDirectory(base_path('storage/theme/.rollback/'.self::NAME));
        File::deleteDirectory(public_path('theme/'.self::NAME));
    }

    private function uploadedPackage(string $version, string $js = 'document.body.dataset.theme="ready"'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tx-theme-');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $manifest = [
            'format' => 2,
            'name' => self::NAME,
            'title' => 'Runtime Theme',
            'version' => $version,
            'entry' => ['type' => 'spa', 'module' => 'dist/index.js'],
            'engines' => ['txboard' => '>=3.6', 'browser' => '>=1 <2', 'contract' => 'core-browser/v1'],
            'browser' => [
                'contract' => 'core-browser/v1',
                'sdk' => ['package' => '@txboard/theme-sdk', 'version' => '>=0.2.0 <1.0.0'],
                'capabilities' => ['identity.session.create'],
            ],
            'assets' => [
                ['path' => 'dist/index.js', 'integrity' => 'sha256-'.base64_encode(hash('sha256', $js, true))],
            ],
            'config' => [
                'defaults' => ['theme_color' => 'blue', 'smtp_password' => 'not-public'],
                'public' => ['theme_color', 'smtp_password'],
            ],
            'dependencies' => ['runtime' => []],
            'security' => ['csp' => 'strict', 'remoteCode' => false],
        ];
        $this->assertTrue($zip->addFromString('txboard-theme.json', json_encode($manifest, JSON_THROW_ON_ERROR)));
        $this->assertTrue($zip->addFromString('dist/index.js', $js));
        $this->assertTrue($zip->close());
        return new UploadedFile($path, 'theme.zip', 'application/zip', null, true);
    }

    public function test_spa_install_switch_render_and_server_public_config(): void
    {
        $runtime = app(ThemeV2Runtime::class);
        $themes = app(ThemeService::class);
        $runtime->install($this->uploadedPackage('1.0.0'));
        $this->assertSame('TXBoard', $themes->getActiveTheme());
        $this->assertSame(2, $themes->getList()[self::NAME]['format']);
        $this->assertFalse(File::exists(public_path('theme/'.self::NAME.'/dist/index.js')));

        $themes->switch(self::NAME);
        $this->assertSame(self::NAME, $themes->getActiveTheme());
        $this->assertTrue(File::isFile(public_path('theme/'.self::NAME.'/dist/index.js')));
        $this->assertFalse(File::exists(public_path('theme/'.self::NAME.'/txboard-theme.json')));
        $response = $this->get('/');
        $response->assertOk()->assertSee('integrity="sha256-', false)
            ;
        $this->assertStringContainsString("script-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertSame(['theme_color' => 'blue'], $themes->getPublicConfig(self::NAME));
        $this->getJson('/txapi/public/site-config')
            ->assertJsonPath('data.frontend_theme', self::NAME)
            ->assertJsonPath('data.theme_config.theme_color', 'blue')
            ->assertJsonMissingPath('data.theme_config.smtp_password');

        $themes->updateConfig(self::NAME, ['theme_color' => 'green', 'smtp_password' => 'secret']);
        $this->assertSame(['theme_color' => 'green'], $themes->getPublicConfig(self::NAME));
        try {
            $themes->delete(self::NAME);
            $this->fail('Current theme must not be deleted');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Current theme cannot be deleted', $e->getMessage());
        }
        $themes->switch('TXBoard');
        $this->assertSame('TXBoard', $themes->getActiveTheme());
        $this->assertDirectoryDoesNotExist(public_path('theme/'.self::NAME));
        $this->assertTrue($themes->exists(self::NAME));
    }

    public function test_active_upgrade_then_rollback_preserves_prior_bundle(): void
    {
        $runtime = app(ThemeV2Runtime::class);
        $themes = app(ThemeService::class);
        $first = 'document.body.dataset.theme="one"';
        $second = 'document.body.dataset.theme="two"';
        $runtime->install($this->uploadedPackage('1.0.0', $first));
        $themes->switch(self::NAME);
        $runtime->install($this->uploadedPackage('1.1.0', $second));
        $this->assertSame($second, File::get(public_path('theme/'.self::NAME.'/dist/index.js')));
        $this->assertSame('1.1.0', $runtime->manifest(self::NAME)['version']);
        $this->assertTrue($runtime->inventory(self::NAME)['can_rollback']);

        $runtime->rollback(self::NAME, true);
        $this->assertSame('1.0.0', $runtime->manifest(self::NAME)['version']);
        $this->assertSame($first, File::get(public_path('theme/'.self::NAME.'/dist/index.js')));
        $this->assertSame(self::NAME, $themes->getActiveTheme());

        $runtime->rollback(self::NAME, true);
        $this->assertSame('1.1.0', $runtime->manifest(self::NAME)['version']);
        $this->assertSame($second, File::get(public_path('theme/'.self::NAME.'/dist/index.js')));
    }

    public function test_unconfigured_runtime_refuses_install_without_mutating_active_theme(): void
    {
        config()->set('theme_v2.sdk_version', '');
        try {
            app(ThemeV2Runtime::class)->install($this->uploadedPackage('1.0.0'));
            $this->fail('An unknown deployed SDK version must block install');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('SDK deployed version', $e->getMessage());
        }
        $this->assertSame('TXBoard', app(ThemeService::class)->getActiveTheme());
        $this->assertDirectoryDoesNotExist(base_path('storage/theme/'.self::NAME));
    }

    public function test_tampered_rollback_snapshot_cannot_replace_active_theme(): void
    {
        $runtime = app(ThemeV2Runtime::class);
        $themes = app(ThemeService::class);
        $runtime->install($this->uploadedPackage('1.0.0', 'console.log("safe")'));
        $themes->switch(self::NAME);
        $runtime->install($this->uploadedPackage('1.1.0', 'console.log("current")'));
        $priorBundle = base_path('storage/theme/.rollback/'.self::NAME.'/dist/index.js');
        File::put($priorBundle, 'console.log("tampered")');
        try {
            $runtime->rollback(self::NAME, true);
            $this->fail('Tampered previous version must not become active');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('integrity mismatch', $e->getMessage());
        }
        $this->assertSame('1.1.0', $runtime->manifest(self::NAME)['version']);
        $this->assertSame('console.log("current")',
            File::get(public_path('theme/'.self::NAME.'/dist/index.js')));
    }

    public function test_incompatible_rollback_refuses_and_retains_current_theme(): void
    {
        $runtime = app(ThemeV2Runtime::class);
        $runtime->install($this->uploadedPackage('1.0.0'));
        $runtime->install($this->uploadedPackage('1.1.0'));
        config()->set('theme_v2.browser_version', '2.0.0');
        try {
            $runtime->rollback(self::NAME, false);
            $this->fail('Incompatible Gateway must reject rollback');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Incompatible Core browser version', $e->getMessage());
        }
        $this->assertSame('1.1.0', $runtime->manifest(self::NAME)['version']);
    }
}
