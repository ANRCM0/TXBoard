<?php

use App\Support\Database\NativeTableName;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable(NativeTableName::runtime('v2_server_route'))) {
            return;
        }

        $hasEnabled = Schema::hasColumn(NativeTableName::runtime('v2_server_route'), 'enabled');
        $hasSort = Schema::hasColumn(NativeTableName::runtime('v2_server_route'), 'sort');

        Schema::table(NativeTableName::runtime('v2_server_route'), function (Blueprint $table) use ($hasEnabled, $hasSort) {
            if (!$hasEnabled) {
                $table->boolean('enabled')->default(true);
            }
            if (!$hasSort) {
                $table->integer('sort')->nullable()->index();
            }
        });

        DB::table(NativeTableName::runtime('v2_server_route'))
            ->orderBy('id')
            ->get(['id', 'sort'])
            ->values()
            ->each(function ($route, int $index) {
                if ($route->sort === null) {
                    DB::table(NativeTableName::runtime('v2_server_route'))
                        ->where('id', $route->id)
                        ->update(['sort' => ($index + 1) * 10]);
                }
            });
    }

    public function down(): void
    {
        if (!Schema::hasTable(NativeTableName::runtime('v2_server_route'))) {
            return;
        }

        $hasSort = Schema::hasColumn(NativeTableName::runtime('v2_server_route'), 'sort');
        $hasEnabled = Schema::hasColumn(NativeTableName::runtime('v2_server_route'), 'enabled');

        Schema::table(NativeTableName::runtime('v2_server_route'), function (Blueprint $table) use ($hasSort, $hasEnabled) {
            if ($hasSort) {
                $table->dropColumn('sort');
            }
            if ($hasEnabled) {
                $table->dropColumn('enabled');
            }
        });
    }
};
