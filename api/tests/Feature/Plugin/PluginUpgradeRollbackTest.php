<?php

namespace Tests\Feature\Plugin;

use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class PluginUpgradeRollbackTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('plugins/Phase3UpgradeRollback'));
        parent::tearDown();
    }

    public function test_failed_upgrade_restores_previous_files_version_and_saved_config(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('zip extension is required');
        }

        $path = base_path('plugins/Phase3UpgradeRollback');
        File::ensureDirectoryExists($path);
        $oldManifest = $this->manifest('1.0.0');
        File::put($path . '/config.json', json_encode($oldManifest, JSON_THROW_ON_ERROR));
        File::put($path . '/stable.txt', 'pre-upgrade-file');

        $config = '{"keep":"saved-settings"}';
        Plugin::create([
            'code' => 'phase3_upgrade_rollback',
            'name' => 'Upgrade rollback fixture',
            'type' => Plugin::TYPE_FEATURE,
            'version' => '1.0.0',
            'is_enabled' => false,
            'config' => $config,
            'installed_at' => now(),
        ]);

        $archive = tempnam(sys_get_temp_dir(), 'txboard-upgrade-');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('config.json', json_encode($this->manifest('1.1.0'), JSON_THROW_ON_ERROR));
        $zip->addFromString('new-only.txt', 'new-version');
        $zip->addFromString('Plugin.php', <<<'PLUGIN'
<?php
namespace Plugin\Phase3UpgradeRollback;

class Plugin extends \App\Services\Plugin\AbstractPlugin
{
    public function update(string $oldVersion, string $newVersion): void
    {
        throw new \RuntimeException('deliberate upgrade failure');
    }
}
PLUGIN
        );
        $this->assertTrue($zip->close());

        try {
            app(PluginManager::class)->upload(
                new UploadedFile($archive, 'rollback.zip', 'application/zip', null, true)
            );
            $this->fail('The deliberately broken upgrade must fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('deliberate upgrade failure', $e->getMessage());
        } finally {
            @unlink($archive);
        }

        $current = Plugin::where('code', 'phase3_upgrade_rollback')->firstOrFail();
        $this->assertSame('1.0.0', $current->version);
        $this->assertFalse($current->is_enabled);
        $this->assertSame($config, $current->config);
        $this->assertSame('pre-upgrade-file', File::get($path . '/stable.txt'));
        $this->assertSame('1.0.0', json_decode(File::get($path . '/config.json'), true)['version']);
        $this->assertFileDoesNotExist($path . '/new-only.txt');
    }

    private function manifest(string $version): array
    {
        return [
            'name' => 'Upgrade rollback fixture',
            'code' => 'phase3_upgrade_rollback',
            'version' => $version,
            'description' => 'Phase 3 failure testing',
            'author' => 'TXBoard',
        ];
    }
}
