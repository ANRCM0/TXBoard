<?php

namespace Tests\Feature\Plugin;

use App\Models\Plugin;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PluginLifecycleSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('plugins/Phase3MissingDependency'));
        HookManager::reset();
        parent::tearDown();
    }

    public function test_missing_plugin_runtime_never_deletes_installed_record_or_config(): void
    {
        $saved = json_encode(['api_key' => 'existing-configuration'], JSON_THROW_ON_ERROR);
        $this->makePlugin('phase3_unavailable', false, $saved);

        try {
            app(PluginManager::class)->enable('phase3_unavailable');
            $this->fail('Enabling a missing implementation must fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unavailable', $e->getMessage());
        }

        $row = Plugin::query()->where('code', 'phase3_unavailable')->firstOrFail();
        $this->assertFalse($row->is_enabled);
        $this->assertSame($saved, $row->config);
    }

    public function test_disable_revokes_old_hooks_even_if_plugin_code_has_disappeared(): void
    {
        $saved = '{"enabled":true}';
        $this->makePlugin('phase3_unavailable', true, $saved);
        HookManager::reset();
        HookManager::registerFilter('order.process', static fn ($value) => $value + 1);

        HookManager::withOwner('phase3_unavailable', static function (): void {
            HookManager::registerFilter('order.process', static fn ($value) => $value * 100);
        });
        $this->assertSame(300, HookManager::filter('order.process', 2));

        $this->assertTrue(app(PluginManager::class)->disable('phase3_unavailable'));
        $this->assertSame(3, HookManager::filter('order.process', 2));
        $this->assertFalse(Plugin::query()->where('code', 'phase3_unavailable')->firstOrFail()->is_enabled);
        $this->assertSame($saved, Plugin::query()->where('code', 'phase3_unavailable')->value('config'));
        $this->assertTrue(app(PluginManager::class)->disable('phase3_unavailable'));
    }

    public function test_core_plugin_cannot_be_deleted_or_uninstalled_during_delete(): void
    {
        $this->makePlugin('epay', true);
        try {
            app(PluginManager::class)->delete('epay');
            $this->fail('Protected plugin delete must fail before touching the database.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('核心插件', $e->getMessage());
        }

        $this->assertTrue(Plugin::query()->where('code', 'epay')->firstOrFail()->is_enabled);
    }

    public function test_missing_dependency_rejected_before_plugin_is_registered(): void
    {
        $path = base_path('plugins/Phase3MissingDependency');
        File::ensureDirectoryExists($path);
        File::put($path . '/config.json', json_encode([
            'name' => 'Dependency Probe',
            'code' => 'phase3_missing_dependency',
            'version' => '1.0.0',
            'description' => 'Dependency test',
            'author' => 'TXBoard',
            'require' => ['not_installed' => '>=1.0.0'],
        ], JSON_THROW_ON_ERROR));

        try {
            app(PluginManager::class)->install('phase3_missing_dependency');
            $this->fail('Missing required dependency must reject installation.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Missing or disabled plugin dependency', $e->getMessage());
        }
        $this->assertDatabaseMissing('tx_plugins', ['code' => 'phase3_missing_dependency']);
    }

    public function test_repeat_install_rejects_duplicate_registration_without_mutation(): void
    {
        $this->makePlugin('phase3_missing_dependency', false, '{"saved":"original"}');
        $path = base_path('plugins/Phase3MissingDependency');
        File::ensureDirectoryExists($path);
        File::put($path . '/config.json', json_encode([
            'name' => 'Repeat Install Fixture',
            'code' => 'phase3_missing_dependency',
            'version' => '1.0.0',
            'description' => 'Repeated install safety',
            'author' => 'TXBoard',
        ], JSON_THROW_ON_ERROR));

        try {
            app(PluginManager::class)->install('phase3_missing_dependency');
            $this->fail('Repeat installation must not register the module again.');
        } catch (\Exception $e) {
            $this->assertSame('Plugin already installed', $e->getMessage());
        }
        $this->assertSame(1, Plugin::query()->where('code', 'phase3_missing_dependency')->count());
        $this->assertSame('{"saved":"original"}',
            Plugin::query()->where('code', 'phase3_missing_dependency')->value('config'));
    }

    private function makePlugin(string $code, bool $enabled, ?string $config = null): void
    {
        Plugin::create([
            'name' => 'Lifecycle Probe',
            'code' => $code,
            'version' => '1.0.0',
            'type' => Plugin::TYPE_FEATURE,
            'is_enabled' => $enabled,
            'config' => $config,
            'installed_at' => now(),
        ]);
    }
}
