<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_native_free_checkout', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('installation_id', 36);
            $table->integer('user_id');
            $table->integer('order_id');
            $table->string('request_key', 128);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->char('purchase_hash', 64);
            $table->string('currency', 3);
            $table->text('result_json');
            $table->char('content_hash', 64);
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('completed_at')->nullable();
            $table->unique(['installation_id', 'user_id', 'key_hash'], 'tx_native_free_checkout_key_uq');
            $table->unique('order_id', 'tx_native_free_checkout_order_uq');
            $table->index(['status', 'id'], 'tx_native_free_checkout_work_idx');
            $table->foreign('order_id', 'tx_native_free_checkout_order_fk')
                ->references('id')->on('tx_order')->restrictOnDelete();
            $table->foreign('user_id', 'tx_native_free_checkout_user_fk')
                ->references('id')->on('tx_user')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_native_free_checkout')->exists()) {
            throw new RuntimeException('NATIVE_FREE_CHECKOUT_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::drop('tx_native_free_checkout');
    }
};
