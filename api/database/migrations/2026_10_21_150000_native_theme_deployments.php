<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('tx_native_theme_deployment')) return;
        Schema::create('tx_native_theme_deployment', function (Blueprint $table): void {
            $table->char('key_hash', 64)->primary();
            $table->uuid('installation_id'); $table->integer('actor_id'); $table->string('module_id', 120);
            $table->string('action', 16); $table->char('input_hash', 64); $table->char('before_revision', 64);
            $table->text('target_json'); $table->string('phase', 16); $table->unsignedBigInteger('created_at'); $table->unsignedBigInteger('updated_at');
            $table->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $table->index(['installation_id', 'module_id', 'phase']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tx_native_theme_deployment') && DB::table('tx_native_theme_deployment')->exists()) throw new RuntimeException('NATIVE_THEME_FORWARD_RECOVERY_REQUIRED');
        Schema::dropIfExists('tx_native_theme_deployment');
    }
};
