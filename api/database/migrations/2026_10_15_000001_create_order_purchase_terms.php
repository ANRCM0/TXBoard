<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_order_purchase_terms', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('plan_id');
            $table->string('period', 32);
            $table->unsignedTinyInteger('order_type');
            $table->unsignedBigInteger('price_minor');
            $table->char('currency', 3);
            $table->unsignedTinyInteger('currency_minor_exponent')->default(2);
            $table->unsignedBigInteger('quota_bytes')->nullable();
            $table->integer('group_id')->nullable();
            $table->unsignedInteger('speed_limit_mbps')->nullable();
            $table->unsignedInteger('device_limit')->nullable();
            $table->json('reset_policy');
            $table->unsignedInteger('duration_months')->nullable();
            $table->string('purchase_kind', 32);
            $table->json('source_snapshot');
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('created_at');
            $table->unique('order_id', 'tx_order_purchase_terms_order_uq');
            $table->index(['user_id', 'created_at'], 'tx_order_purchase_terms_user_idx');
            $table->foreign('order_id', 'tx_order_purchase_terms_order_fk')->references('id')->on('tx_order')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tx_order_purchase_terms') && DB::table('tx_order_purchase_terms')->exists()) {
            throw new \RuntimeException('Order purchase terms exist; fix forward or restore a verified backup.');
        }
        Schema::dropIfExists('tx_order_purchase_terms');
    }
};
