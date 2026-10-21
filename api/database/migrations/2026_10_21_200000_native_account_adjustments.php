<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('tx_native_account_adjustment')) return;
        Schema::create('tx_native_account_adjustment', function (Blueprint $table): void {
            $table->bigIncrements('id'); $table->uuid('installation_id'); $table->integer('actor_id'); $table->integer('user_id');
            $table->uuid('request_key'); $table->char('currency', 3); $table->string('bucket', 32); $table->string('direction', 6);
            $table->unsignedBigInteger('amount_minor'); $table->unsignedBigInteger('journal_id');
            $table->char('request_hash', 64); $table->longText('request_snapshot'); $table->longText('result_snapshot');
            $table->char('content_hash', 64); $table->unsignedBigInteger('created_at');
            $table->unique(['installation_id', 'actor_id', 'request_key'], 'tx_native_adjust_actor_key_uq');
            $table->unique('journal_id', 'tx_native_adjust_journal_uq'); $table->index(['user_id', 'id'], 'tx_native_adjust_user_id_idx');
            $table->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('journal_id')->references('id')->on('tx_money_journal')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tx_native_account_adjustment') && DB::table('tx_native_account_adjustment')->exists()) {
            throw new RuntimeException('NATIVE_BILLING_FORWARD_RECOVERY_REQUIRED');
        }
        Schema::dropIfExists('tx_native_account_adjustment');
    }
};
