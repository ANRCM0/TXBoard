<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_settings')) {
            return;
        }

        $settings = DB::table('v2_settings');

        $settings->where('name', 'app_name')
            ->whereIn('value', ['XBoard', 'Xboard'])
            ->update(['value' => 'TXBoard']);

        $settings->where('name', 'app_description')
            ->whereIn('value', ['Xboard is best', 'XBoard is best'])
            ->update(['value' => 'TXBoard control plane']);

        foreach (['frontend_theme', 'current_theme'] as $name) {
            $settings->where('name', $name)
                ->where('value', 'Xboard')
                ->update(['value' => 'TXBoard']);
        }

        $legacyTheme = DB::table('v2_settings')->where('name', 'theme_Xboard')->first();
        $newTheme = DB::table('v2_settings')->where('name', 'theme_TXBoard')->first();

        if ($legacyTheme && !$newTheme) {
            DB::table('v2_settings')
                ->where('id', $legacyTheme->id)
                ->update(['name' => 'theme_TXBoard']);
        }
    }

    public function down(): void
    {
        // Branding migration is intentionally one-way. Reverting a deploy must
        // not overwrite an operator's post-migration product name or theme.
    }
};
