<?php

namespace Tests\Feature\Plugin;

use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class PluginStageFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('plugins/Phase3StageFailure'));
        parent::tearDown();
    }

    public function test_failed_staging_cannot_delete_working_plugin_files(): void
    {
        $root = base_path('plugins/Phase3StageFailure');
        File::ensureDirectoryExists($root);
        File::put($root . '/config.json', json_encode($this->manifest('1.0.0'), JSON_THROW_ON_ERROR));
        File::put($root . '/keep.txt', 'stable code');
        Plugin::create([
            'name' => 'Stage Failure',
            'code' => 'phase3_stage_failure',
            'version' => '1.0.0',
            'type' => Plugin::TYPE_FEATURE,
            'config' => '{"keep":"true"}',
            'is_enabled' => false,
            'installed_at' => now(),
        ]);

        $archive = tempnam(sys_get_temp_dir(), 'txboard-stage-failure-');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString('config.json', json_encode($this->manifest('2.0.0'), JSON_THROW_ON_ERROR));
        $zip->addFromString('Plugin.php', "<?php\n// upgrade fixture");
        $this->assertTrue($zip->close());

        File::partialMock()->shouldReceive('copyDirectory')->once()->andReturn(false);
        try {
            app(PluginManager::class)->upload(
                new UploadedFile($archive, 'new.zip', 'application/zip', null, true)
            );
            $this->fail('A failed staging copy must reject the upgrade.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Failed to stage plugin package', $e->getMessage());
        } finally {
            @unlink($archive);
        }

        $this->assertSame('stable code', File::get($root . '/keep.txt'));
        $this->assertSame('1.0.0', json_decode(File::get($root . '/config.json'), true)['version']);
        $row = Plugin::query()->where('code', 'phase3_stage_failure')->firstOrFail();
        $this->assertSame('1.0.0', $row->version);
        $this->assertSame('{"keep":"true"}', $row->config);
    }

    private function manifest(string $version): array
    {
        return [
            'name' => 'Stage Failure',
            'code' => 'phase3_stage_failure',
            'version' => $version,
            'description' => 'Safe staging test',
            'author' => 'TXBoard',
        ];
    }
}
