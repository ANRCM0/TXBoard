<?php

namespace Tests\Feature\Config;

use App\Models\Setting as SettingModel;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class RetiredRuntimeSettingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const RETIRED = [
        'server_ws_enable', 'server_ws_url', 'recaptcha_enable',
    ];

    public function test_migration_is_idempotent_and_preserves_active_node_and_captcha_settings(): void
    {
        foreach (self::RETIRED as $key) {
            DB::table('v2_settings')->insert(['name' => $key, 'value' => 'obsolete']);
        }
        DB::table('v2_settings')->insert([
            ['name' => 'server_token', 'value' => 'current-node-token'],
            ['name' => 'captcha_enable', 'value' => '1'],
            ['name' => 'frontend_theme', 'value' => 'TXBoard'],
        ]);
        $cache = Cache::store(config('cache.setting_store', 'redis'));
        $cache->put(Setting::CACHE_KEY, ['server_ws_url' => 'wss://old.test/ws'], 3600);

        $migration = require database_path('migrations/2026_10_10_000001_purge_retired_node_and_captcha_settings.php');
        $migration->up();
        $migration->up();

        $this->assertSame(0, DB::table('v2_settings')->whereIn('name', self::RETIRED)->count());
        $this->assertSame('current-node-token', DB::table('v2_settings')->where('name', 'server_token')->value('value'));
        $this->assertSame('1', DB::table('v2_settings')->where('name', 'captcha_enable')->value('value'));
        $this->assertSame('TXBoard', DB::table('v2_settings')->where('name', 'frontend_theme')->value('value'));
        $this->assertNull($cache->get(Setting::CACHE_KEY));
        $migration->down();
        $this->assertSame(0, DB::table('v2_settings')->whereIn('name', self::RETIRED)->count());
    }

    public function test_old_keys_are_unreadable_and_unwritable_through_model_and_cache(): void
    {
        $cache = Cache::store(config('cache.setting_store', 'redis'));
        $cache->put(Setting::CACHE_KEY, [
            'server_ws_enable' => true,
            'server_ws_url' => 'wss://old.test/ws',
            'recaptcha_enable' => true,
            'captcha_enable' => true,
        ], 3600);
        $settings = new Setting();
        foreach (self::RETIRED as $key) {
            $this->assertSame('retired', $settings->get($key, 'retired'));
            $this->assertArrayNotHasKey($key, $settings->toArray());
            $this->assertSame([], $settings->getBatch([$key]));
            foreach ([
                fn () => $settings->set($key, 'bad'),
                fn () => SettingModel::createOrUpdate($key, 'bad'),
            ] as $write) {
                try {
                    $write();
                    $this->fail("Retired key {$key} was writable");
                } catch (InvalidArgumentException $e) {
                    $this->assertStringContainsString('Retired TXBoard runtime', $e->getMessage());
                }
            }
        }
        $this->assertTrue($settings->get('captcha_enable'));
        try {
            $settings->save(['app_name' => 'should-not-save', 'SERVER_WS_URL' => 'wss://bad.test']);
            $this->fail('Mixed batch should fail atomically');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Retired TXBoard runtime', $e->getMessage());
        }
        $this->assertNull(DB::table('v2_settings')->where('name', 'app_name')->value('value'));
    }
}
