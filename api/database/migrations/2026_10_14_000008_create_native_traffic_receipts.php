<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tx_native_traffic_receipt', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('node_id');
            $table->string('batch_id', 80);
            $table->char('raw_payload_hash', 64);
            $table->longText('settlement_input');
            $table->char('ledger_payload_hash', 64);
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('accepted_at');
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('settled_at')->nullable();
            $table->unsignedBigInteger('quota_checked_at')->nullable();
            $table->unsignedBigInteger('attempts')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->unique(['node_id', 'batch_id'], 'uq_native_traffic_node_batch');
            $table->index(['status', 'id'], 'idx_native_traffic_pending');
        });
    }

    public function down(): void
    {
        // A receipt is the only durable copy of an acknowledged native batch.
        if (Schema::hasTable('tx_native_traffic_receipt') && \Illuminate\Support\Facades\DB::table('tx_native_traffic_receipt')->exists()) {
            throw new \RuntimeException('NATIVE_TRAFFIC_RECEIPTS_NOT_EMPTY');
        }
        Schema::dropIfExists('tx_native_traffic_receipt');
    }
};
