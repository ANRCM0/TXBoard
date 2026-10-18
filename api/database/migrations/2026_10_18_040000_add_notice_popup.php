<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tx_notice') || Schema::hasColumn('tx_notice', 'popup')) return;
        Schema::table('tx_notice', static function (Blueprint $table): void {
            $table->boolean('popup')->default(false);
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('tx_notice', 'popup')) return;
        if (DB::table('tx_notice')->where('popup', true)->exists()) {
            throw new RuntimeException('NOTICE_POPUP_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::table('tx_notice', static fn (Blueprint $table) => $table->dropColumn('popup'));
    }
};
