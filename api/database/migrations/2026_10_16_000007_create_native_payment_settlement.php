<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tx_order', function (Blueprint $table): void {
            $table->unsignedBigInteger('paid_at')->nullable()->change();
        });
        Schema::table('tx_payment_receipt', function (Blueprint $table): void {
            $table->unique(['id', 'installation_id', 'user_id', 'order_id', 'payment_id', 'attempt_id'],
                'tx_payment_receipt_settlement_owner_uq');
        });
        Schema::table('tx_money_journal', function (Blueprint $table): void {
            $table->unique(['id', 'installation_id', 'user_id'], 'tx_money_journal_settlement_owner_uq');
        });
        Schema::create('tx_payment_settlement', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('installation_id', 36);
            $table->integer('user_id');
            $table->integer('order_id');
            $table->integer('payment_id');
            $table->unsignedBigInteger('attempt_id');
            $table->unsignedBigInteger('receipt_id');
            $table->unsignedBigInteger('journal_id');
            $table->string('decision', 32);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('occurred_at');
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('created_at');
            $table->unique('receipt_id', 'tx_payment_settlement_receipt_uq');
            $table->unique('attempt_id', 'tx_payment_settlement_attempt_uq');
            $table->unique('journal_id', 'tx_payment_settlement_journal_uq');
            $table->unique(['id', 'installation_id', 'user_id', 'order_id', 'receipt_id'], 'tx_payment_settlement_owner_uq');
            $table->foreign(['receipt_id', 'installation_id', 'user_id', 'order_id', 'payment_id', 'attempt_id'],
                'tx_payment_settlement_receipt_fk')->references(['id', 'installation_id', 'user_id', 'order_id', 'payment_id', 'attempt_id'])
                ->on('tx_payment_receipt')->restrictOnDelete();
            $table->foreign(['journal_id', 'installation_id', 'user_id'], 'tx_payment_settlement_journal_fk')
                ->references(['id', 'installation_id', 'user_id'])->on('tx_money_journal')->restrictOnDelete();
        });
        Schema::create('tx_order_payment', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('installation_id', 36);
            $table->integer('user_id');
            $table->integer('order_id');
            $table->unsignedBigInteger('settlement_id')->nullable();
            $table->unsignedBigInteger('receipt_id')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('created_at');
            $table->unique('order_id', 'tx_order_payment_order_uq');
            $table->unique('settlement_id', 'tx_order_payment_settlement_uq');
            $table->unique('receipt_id', 'tx_order_payment_receipt_uq');
            $table->foreign(['settlement_id', 'installation_id', 'user_id', 'order_id', 'receipt_id'], 'tx_order_payment_owner_fk')
                ->references(['id', 'installation_id', 'user_id', 'order_id', 'receipt_id'])
                ->on('tx_payment_settlement')->restrictOnDelete();
            $table->foreign(['order_id', 'user_id'], 'tx_order_payment_order_fk')
                ->references(['id', 'user_id'])->on('tx_order')->restrictOnDelete();
        });
        Schema::create('tx_billing_work', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('event_key', 64);
            $table->string('kind', 32);
            $table->char('installation_id', 36);
            $table->integer('user_id');
            $table->integer('order_id');
            $table->unsignedBigInteger('receipt_id')->nullable();
            $table->unsignedBigInteger('settlement_id')->nullable();
            $table->char('content_hash', 64);
            $table->string('status', 16);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('completed_at')->nullable();
            $table->unique('event_key', 'tx_billing_work_event_uq');
            $table->unique('settlement_id', 'tx_billing_work_settlement_uq');
            $table->index(['status', 'id'], 'tx_billing_work_pending_idx');
            $table->foreign(['settlement_id', 'installation_id', 'user_id', 'order_id', 'receipt_id'], 'tx_billing_work_owner_fk')
                ->references(['id', 'installation_id', 'user_id', 'order_id', 'receipt_id'])
                ->on('tx_payment_settlement')->restrictOnDelete();
            $table->foreign(['order_id', 'user_id'], 'tx_billing_work_order_fk')
                ->references(['id', 'user_id'])->on('tx_order')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['tx_billing_work', 'tx_order_payment', 'tx_payment_settlement'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('NATIVE_PAYMENT_SETTLEMENT_POPULATED_ROLLBACK_REFUSED');
            }
        }
        if (DB::table('tx_order')->where('paid_at', '>', 2147483647)->exists()) {
            throw new RuntimeException('NATIVE_PAYMENT_TIMESTAMP_NARROWING_REFUSED');
        }
        Schema::dropIfExists('tx_billing_work');
        Schema::dropIfExists('tx_order_payment');
        Schema::dropIfExists('tx_payment_settlement');
        Schema::table('tx_money_journal', function (Blueprint $table): void {
            $table->dropUnique('tx_money_journal_settlement_owner_uq');
        });
        Schema::table('tx_payment_receipt', function (Blueprint $table): void {
            $table->dropUnique('tx_payment_receipt_settlement_owner_uq');
        });
        Schema::table('tx_order', function (Blueprint $table): void {
            $table->integer('paid_at')->nullable()->change();
        });
    }
};
