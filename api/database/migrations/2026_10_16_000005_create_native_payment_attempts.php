<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tx_order', function (Blueprint $table): void {
            $table->unique(['id', 'user_id'], 'tx_payment_order_owner_uq');
        });
        Schema::table('tx_order_purchase_terms', function (Blueprint $table): void {
            $table->unique(['id', 'order_id', 'user_id'], 'tx_payment_purchase_owner_uq');
        });
        Schema::create('tx_payment_attempt', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('installation_id', 36);
            $table->integer('user_id');
            $table->string('intent_type', 16)->default('purchase');
            $table->unsignedBigInteger('recharge_id')->nullable();
            $table->integer('order_id')->nullable();
            $table->unsignedBigInteger('purchase_terms_id')->nullable();
            $table->integer('payment_id');
            $table->string('provider', 32);
            $table->string('provider_account_ref', 128);
            $table->string('order_trade_no', 36);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->char('purchase_content_hash', 64);
            $table->string('request_key', 128);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->char('attempt_hash', 64);
            $table->string('status', 16);
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
            $table->unique(['installation_id', 'user_id', 'key_hash'], 'tx_payment_attempt_key_uq');
            $table->unique(['id', 'installation_id', 'user_id', 'order_id', 'payment_id'], 'tx_payment_attempt_owner_uq');
            $table->unique(['id', 'installation_id', 'user_id', 'payment_id'], 'tx_payment_attempt_financial_owner_uq');
            $table->index(['order_id', 'id'], 'tx_payment_attempt_order_idx');
            $table->foreign('user_id', 'tx_payment_attempt_user_fk')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('payment_id', 'tx_payment_attempt_payment_fk')->references('id')->on('tx_payment')->restrictOnDelete();
            $table->foreign(['order_id', 'user_id'], 'tx_payment_attempt_order_fk')
                ->references(['id', 'user_id'])->on('tx_order')->restrictOnDelete();
            $table->foreign(['purchase_terms_id', 'order_id', 'user_id'], 'tx_payment_attempt_purchase_fk')
                ->references(['id', 'order_id', 'user_id'])->on('tx_order_purchase_terms')->restrictOnDelete();
        });
        Schema::create('tx_payment_receipt', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('installation_id', 36);
            $table->integer('user_id');
            $table->string('intent_type', 16)->default('purchase');
            $table->unsignedBigInteger('recharge_id')->nullable();
            $table->integer('order_id')->nullable();
            $table->integer('payment_id');
            $table->unsignedBigInteger('attempt_id');
            $table->string('provider', 32);
            $table->string('provider_account_ref', 128);
            $table->string('transaction_ref', 128);
            $table->char('identity_hash', 64);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('occurred_at');
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('created_at');
            // A provider account's transaction cannot be spent through another payment configuration.
            $table->unique(['installation_id', 'identity_hash'], 'tx_payment_receipt_identity_uq');
            $table->unique('attempt_id', 'tx_payment_receipt_attempt_uq');
            $table->foreign(['attempt_id', 'installation_id', 'user_id', 'order_id', 'payment_id'], 'tx_payment_receipt_owner_fk')
                ->references(['id', 'installation_id', 'user_id', 'order_id', 'payment_id'])
                ->on('tx_payment_attempt')->restrictOnDelete();
            $table->foreign(['attempt_id', 'installation_id', 'user_id', 'payment_id'], 'tx_payment_receipt_financial_owner_fk')
                ->references(['id', 'installation_id', 'user_id', 'payment_id'])
                ->on('tx_payment_attempt')->restrictOnDelete();
            $table->foreign('user_id', 'tx_payment_receipt_user_fk')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('payment_id', 'tx_payment_receipt_payment_fk')->references('id')->on('tx_payment')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['tx_payment_receipt', 'tx_payment_attempt'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('NATIVE_PAYMENT_POPULATED_ROLLBACK_REFUSED');
            }
        }
        Schema::drop('tx_payment_receipt');
        Schema::drop('tx_payment_attempt');
        Schema::table('tx_order_purchase_terms', function (Blueprint $table): void {
            $table->dropUnique('tx_payment_purchase_owner_uq');
        });
        Schema::table('tx_order', function (Blueprint $table): void {
            $table->dropUnique('tx_payment_order_owner_uq');
        });
    }
};
