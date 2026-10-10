<?php

use App\Support\Database\NativeTableName;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purge the abandoned site-wide user-appearance values. Presentation is now
 * configured in each theme's theme_<name> settings; frontend_theme remains
 * the active-theme selector and must never be removed here.
 *
 * This migration intentionally has no destructive rollback: restoring old
 * appearance settings after a code rollback would reintroduce stale state.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable(NativeTableName::runtime('v2_settings'))) {
            DB::table(NativeTableName::runtime('v2_settings'))
                ->whereIn(DB::raw('LOWER(name)'), [
                    'frontend_theme_sidebar',
                    'frontend_theme_header',
                    'frontend_theme_color',
                    'frontend_background_url',
                ])
                ->delete();
        }

        // Setting values are cached indefinitely, shared by all API workers.
        // Do not silently swallow errors: a failed cache purge must make this
        // migration retryable instead of reporting an incomplete cleanup.
        Cache::store(config('cache.setting_store', 'redis'))
            ->forget('admin_settings');
    }

    public function down(): void
    {
        // Deliberately irreversible. No legacy values are recreated.
    }
};
