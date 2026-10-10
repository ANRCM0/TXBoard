<?php

namespace Tests\Feature\Plugin;

use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PluginInstallSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('plugins/Phase3MissingRuntime'));
        parent::tearDown();
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
