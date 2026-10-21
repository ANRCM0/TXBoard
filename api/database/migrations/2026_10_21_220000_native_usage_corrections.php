<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_native_usage_correction_head', function (Blueprint $table): void {
            $table->unsignedBigInteger('entry_id')->primary(); $table->uuid('installation_id'); $table->unsignedBigInteger('version'); $table->char('entry_revision', 64);
            $table->foreign('entry_id')->references('id')->on('tx_usage_entry')->restrictOnDelete();
        });
        Schema::create('tx_native_usage_correction', function (Blueprint $table): void {
            $table->bigIncrements('id'); $table->uuid('installation_id'); $table->integer('actor_id'); $table->integer('user_id');
            $table->uuid('request_key'); $table->unsignedBigInteger('entry_id'); $table->unsignedBigInteger('period_id'); $table->unsignedBigInteger('version');
            $table->char('request_hash', 64); $table->longText('request_json'); $table->longText('source_json'); $table->longText('result_json');
            $table->char('content_hash', 64); $table->unsignedBigInteger('created_at');
            $table->unique(['installation_id', 'actor_id', 'request_key'], 'tx_usage_correction_actor_key_uq');
            $table->unique(['entry_id', 'version'], 'tx_usage_correction_entry_version_uq');
            $table->index(['period_id', 'id'], 'tx_usage_correction_period_id_idx'); $table->index(['user_id', 'id'], 'tx_usage_correction_user_id_idx');
            $table->foreign('entry_id')->references('id')->on('tx_usage_entry')->restrictOnDelete();
            $table->foreign('period_id')->references('id')->on('tx_usage_period')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('tx_user')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        if (DB::table('tx_native_usage_correction')->exists()) throw new RuntimeException('NATIVE_USAGE_FORWARD_RECOVERY_REQUIRED');
        Schema::dropIfExists('tx_native_usage_correction'); Schema::dropIfExists('tx_native_usage_correction_head');
    }
};
