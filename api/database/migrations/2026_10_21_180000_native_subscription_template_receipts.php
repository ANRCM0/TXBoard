<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tx_native_subscription_template_receipt')) {
            Schema::create('tx_native_subscription_template_receipt', function (Blueprint $table): void {
                $table->string('receipt_key', 64)->primary();
                $table->unsignedBigInteger('actor_id');
                $table->string('operation', 20);
                $table->string('input_digest', 64);
                $table->text('result_ciphertext');
                $table->unsignedBigInteger('created_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tx_native_subscription_template_receipt') && DB::table('tx_native_subscription_template_receipt')->exists()) {
            throw new RuntimeException('SUBSCRIPTION_TEMPLATE_RECEIPTS_FORWARD_RECOVERY_REQUIRED');
        }
        Schema::dropIfExists('tx_native_subscription_template_receipt');
    }
};
