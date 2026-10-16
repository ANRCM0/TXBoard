<?php

namespace Tests\Feature\Plugin;

use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PluginInstallSafetyTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('plugins/Phase3MissingRuntime'));
        File::deleteDirectory(base_path('plugins/RetiredPackageFixture'));
        parent::tearDown();
    }

    public function test_enabled_old_manifest_is_rejected_before_loading_php(): void
    {
        $path = base_path('plugins/RetiredPackageFixture');
        File::ensureDirectoryExists($path);
        File::put($path.'/config.json', json_encode([
            'name' => 'Old package', 'code' => 'retired_package_fixture', 'version' => '1.0.0',
            'description' => 'No current package schema', 'author' => 'TXBoard',
        ], JSON_THROW_ON_ERROR));
        File::put($path.'/Plugin.php', '<?php throw new \RuntimeException("old code executed");');
        Plugin::create(['name' => 'Old package', 'code' => 'retired_package_fixture',
            'version' => '1.0.0', 'is_enabled' => true, 'installed_at' => now()]);
        $manager = app(PluginManager::class);
        $this->assertArrayNotHasKey('retired_package_fixture', $manager->getEnabledPlugins());
        $method = new \ReflectionMethod(PluginManager::class, 'loadPlugin');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid current plugin package');
        $method->invoke($manager, 'retired_package_fixture');
    }

    public function test_missing_plugin_implementation_rejects_before_transaction_or_registration(): void
    {
        $path = base_path('plugins/Phase3MissingRuntime');
        File::ensureDirectoryExists($path);
        File::put($path . '/config.json', json_encode([
            'name' => 'Missing Runtime',
            'code' => 'phase3_missing_runtime',
            'version' => '1.0.0',
            'description' => 'Safety fixture',
            'author' => 'TXBoard',
            'package' => ['schema' => 1],
        ], JSON_THROW_ON_ERROR));

        $before = DB::transactionLevel();
        try {
            app(PluginManager::class)->install('phase3_missing_runtime');
            $this->fail('Missing PHP implementation must never be installed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('implementation not found', $e->getMessage());
        }
        $this->assertSame($before, DB::transactionLevel());
        $this->assertFalse(Plugin::query()->where('code', 'phase3_missing_runtime')->exists());
        $this->assertFileExists($path . '/config.json');
    }
}
