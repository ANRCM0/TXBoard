<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Native WS is configured by TXBOARD_NATIVE_NODE_WS_ENABLED and the proxy,
 * not by old mutable admin settings. captcha_enable is the canonical switch.
 * Do not drop v2_* tables: these are active ledger and identity structures.
 */
return new class extends Migration
{
    private const RETIRED_KEYS = [
        'server_ws_enable',
        'server_ws_url',
        'recaptcha_enable',
    ];

    public function up(): void
    {
        if (Schema::hasTable('v2_settings')) {
            DB::table('v2_settings')
                ->whereIn(DB::raw('LOWER(name)'), self::RETIRED_KEYS)
                ->delete();
        }

        // Shared Redis setting cache must be invalidated on every worker.
        // A failed purge aborts migration rather than leaving stale settings.
        Cache::store(config('cache.setting_store', 'redis'))
            ->forget('admin_settings');
    }

    public function down(): void
    {
        // Deliberately one-way: restoring obsolete feature switches is unsafe.
    }
};
