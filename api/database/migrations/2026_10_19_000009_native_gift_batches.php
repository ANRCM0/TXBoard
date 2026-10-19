<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_native_gift_batch', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('installation_id'); $t->integer('issuer_id');
            $t->text('terms_json'); $t->char('terms_hash', 64); $t->unsignedBigInteger('created_at');
            $t->index(['installation_id', 'issuer_id', 'created_at', 'id'], 'tx_gift_batch_issuer_idx');
            $t->foreign('issuer_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
        Schema::table('tx_native_gift_card', function (Blueprint $t): void {
            $t->uuid('batch_id')->nullable();
            $t->index(['batch_id', 'id'], 'tx_gift_card_batch_idx');
            $t->foreign('batch_id')->references('id')->on('tx_native_gift_batch')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('tx_native_gift_batch') && DB::table('tx_native_gift_batch')->exists())
            || (Schema::hasColumn('tx_native_gift_card', 'batch_id') && DB::table('tx_native_gift_card')->whereNotNull('batch_id')->exists())) {
            throw new RuntimeException('NATIVE_GIFT_BATCH_FORWARD_RECOVERY_REQUIRED');
        }
        if (Schema::hasColumn('tx_native_gift_card', 'batch_id')) Schema::table('tx_native_gift_card', function (Blueprint $table): void {
            $table->dropForeign(['batch_id']);
            $table->dropIndex('tx_gift_card_batch_idx');
            $table->dropColumn('batch_id');
        });
        Schema::dropIfExists('tx_native_gift_batch');
    }
};
