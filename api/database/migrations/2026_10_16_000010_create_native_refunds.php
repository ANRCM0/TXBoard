<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_native_refund', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('installation_id', 36);
            $table->integer('user_id');
            $table->integer('actor_id');
            $table->string('source_type', 16);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('receipt_id')->nullable();
            $table->unsignedBigInteger('order_payment_id')->nullable();
            $table->integer('order_id')->nullable();
            $table->unsignedBigInteger('source_journal_id');
            $table->unsignedBigInteger('journal_id');
            $table->string('request_key', 128);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('cumulative_minor');
            $table->char('currency', 3);
            $table->string('destination', 16);
            $table->string('reason', 64);
            $table->string('entitlement_effect', 32);
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('created_at');
            $table->unique(['installation_id', 'user_id', 'key_hash'], 'tx_native_refund_key_uq');
            $table->unique('journal_id', 'tx_native_refund_journal_uq');
            $table->index(['installation_id', 'user_id', 'source_type', 'source_id', 'id'], 'tx_native_refund_source_idx');
            $table->foreign('user_id', 'tx_native_refund_user_fk')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('actor_id', 'tx_native_refund_actor_fk')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('installation_id', 'tx_native_refund_installation_fk')
                ->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
            $table->foreign('receipt_id', 'tx_native_refund_receipt_fk')->references('id')->on('tx_payment_receipt')->restrictOnDelete();
            $table->foreign('order_payment_id', 'tx_native_refund_payment_fk')->references('id')->on('tx_order_payment')->restrictOnDelete();
            foreach (['source_journal_id', 'journal_id'] as $field) {
                $table->foreign([$field, 'installation_id', 'user_id'], 'tx_native_refund_'.$field.'_fk')
                    ->references(['id', 'installation_id', 'user_id'])->on('tx_money_journal')->restrictOnDelete();
            }
            $table->foreign(['order_id', 'user_id'], 'tx_native_refund_order_fk')
                ->references(['id', 'user_id'])->on('tx_order')->restrictOnDelete();
            $table->foreign('subscription_id', 'tx_native_refund_subscription_fk')->references('id')->on('tx_subscription')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_native_refund')->exists()) {
            throw new RuntimeException('NATIVE_REFUND_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::dropIfExists('tx_native_refund');
    }
};
