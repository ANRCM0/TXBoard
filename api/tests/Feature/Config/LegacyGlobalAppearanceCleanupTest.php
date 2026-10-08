<?php

namespace Tests\Feature\Config;

use App\Models\Setting as SettingModel;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class LegacyGlobalAppearanceCleanupTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_KEYS = [
        'frontend_theme_sidebar',
        'frontend_theme_header',
        'frontend_theme_color',
        'frontend_background_url',
    ];

    public function test_one_way_migration_purges_only_old_global_appearance_and_shared_cache(): void
    {
        foreach (self::OLD_KEYS as $key) {
            DB::table('v2_settings')->insert(['name' => $key, 'value' => 'obsolete']);
        }
        DB::table('v2_settings')->insert([
            ['name' => 'frontend_theme', 'value' => 'TXBoard'],
            ['name' => 'theme_TXBoard', 'value' => '{"theme_color":"blue"}'],
            ['name' => 'app_name', 'value' => 'Original site'],
        ]);

        $cache = Cache::store(config('cache.setting_store', 'redis'));
        $cache->put(Setting::CACHE_KEY, [
            'frontend_theme_color' => 'obsolete',
            'frontend_theme' => 'TXBoard',
        ], 3600);

        $migration = require database_path('migrations/2026_10_08_000002_purge_legacy_frontend_appearance.php');
        $migration->up();
        $migration->up(); // Safe to rerun after an interrupted rollout.

        $this->assertSame(0, DB::table('v2_settings')->whereIn('name', self::OLD_KEYS)->count());
        $this->assertSame('TXBoard', DB::table('v2_settings')->where('name', 'frontend_theme')->value('value'));
        $this->assertSame('{"theme_color":"blue"}', DB::table('v2_settings')->where('name', 'theme_TXBoard')->value('value'));
        $this->assertSame('Original site', DB::table('v2_settings')->where('name', 'app_name')->value('value'));
        $this->assertNull($cache->get(Setting::CACHE_KEY));

        $migration->down();
        $this->assertSame(0, DB::table('v2_settings')->whereIn('name', self::OLD_KEYS)->count());
    }

    public function test_retired_appearance_keys_cannot_be_recreated_via_model_or_settings_service(): void
    {
        $settings = app(Setting::class);
        foreach (self::OLD_KEYS as $key) {
            try {
                $settings->set($key, 'invalid');
                $this->fail("Setting::set accepted retired field {$key}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Retired global appearance', $e->getMessage());
            }

            try {
                SettingModel::createOrUpdate($key, 'invalid');
                $this->fail("SettingModel::createOrUpdate accepted retired field {$key}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Retired global appearance', $e->getMessage());
            }
        }
        $this->assertSame(0, DB::table('v2_settings')->whereIn('name', self::OLD_KEYS)->count());

        // Mixed valid/retired batches must fail without partly saving valid keys.
        try {
            $settings->save(['site_cleanup_guard' => 'should-not-save', 'FRONTEND_THEME_COLOR' => 'black']);
            $this->fail('Batch accepted retired appearance field');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Retired global appearance', $e->getMessage());
        }
        $this->assertNull(DB::table('v2_settings')->where('name', 'site_cleanup_guard')->value('value'));
        $settings->save(['frontend_theme' => 'TXBoard']);
        $this->assertSame('TXBoard', $settings->get('frontend_theme'));
    }

    public function test_cached_legacy_values_are_not_exposed_by_settings_reads(): void
    {
        $cache = Cache::store(config('cache.setting_store', 'redis'));
        $cache->put(Setting::CACHE_KEY, [
            'frontend_theme_color' => 'obsolete',
            'frontend_background_url' => 'https://old.example',
            'frontend_theme' => 'TXBoard',
        ], 3600);
        $settings = new Setting();

        $this->assertSame('missing', $settings->get('frontend_theme_color', 'missing'));
        $this->assertSame('TXBoard', $settings->get('frontend_theme'));
        $this->assertArrayNotHasKey('frontend_theme_color', $settings->toArray());
        $this->assertArrayNotHasKey('frontend_background_url', $settings->toArray());
        $this->assertSame([], $settings->getBatch(['frontend_theme_color']));
    }
}
