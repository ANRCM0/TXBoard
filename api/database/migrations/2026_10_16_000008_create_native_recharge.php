<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_native_recharge', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('installation_id', 36);
            $table->integer('user_id');
            $table->integer('payment_id');
            $table->string('trade_no', 36);
            $table->string('request_key', 128);
            $table->char('key_hash', 64);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->char('content_hash', 64);
            $table->string('status', 16);
            $table->unsignedBigInteger('receipt_id')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('paid_at')->nullable();
            $table->unique('trade_no', 'tx_native_recharge_trade_uq');
            $table->unique(['installation_id', 'user_id', 'key_hash'], 'tx_native_recharge_key_uq');
            $table->unique(['id', 'installation_id', 'user_id'], 'tx_native_recharge_owner_uq');
            $table->foreign('installation_id', 'tx_native_recharge_installation_fk')
                ->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
            $table->foreign('user_id', 'tx_native_recharge_user_fk')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('payment_id', 'tx_native_recharge_payment_fk')->references('id')->on('tx_payment')->restrictOnDelete();
            $table->foreign('receipt_id', 'tx_native_recharge_receipt_fk')->references('id')->on('tx_payment_receipt')->restrictOnDelete();
            $table->foreign('journal_id', 'tx_native_recharge_journal_fk')->references('id')->on('tx_money_journal')->restrictOnDelete();
        });
        foreach (['tx_payment_attempt', 'tx_payment_receipt', 'tx_payment_dispatch'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->foreign(['recharge_id', 'installation_id', 'user_id'], $name.'_recharge_owner_fk')
                    ->references(['id', 'installation_id', 'user_id'])->on('tx_native_recharge')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('tx_native_recharge')->exists()
            || DB::table('tx_payment_attempt')->whereNotNull('recharge_id')->exists()) {
            throw new RuntimeException('NATIVE_RECHARGE_POPULATED_ROLLBACK_REFUSED');
        }
        foreach (['tx_payment_dispatch', 'tx_payment_receipt', 'tx_payment_attempt'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropForeign($name.'_recharge_owner_fk'));
        }
        Schema::dropIfExists('tx_native_recharge');
    }
};
