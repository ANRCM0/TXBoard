<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('tx_usage_entry')) {
            Schema::table('tx_usage_entry', function (Blueprint $table): void {
                $table->index(['usage_period_id', 'batch_id'], 'tx_usage_entry_period_batch_idx');
            });
        }
        Schema::create('tx_order_upgrade_credit', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('installation_id', 36);
            $table->integer('user_id');
            $table->integer('order_id');
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('terms_id');
            $table->unsignedBigInteger('period_id');
            $table->unsignedBigInteger('offer_id');
            $table->string('currency', 3);
            $table->unsignedBigInteger('list_minor');
            $table->unsignedBigInteger('credit_minor');
            $table->unsignedBigInteger('net_minor');
            $table->unsignedBigInteger('quoted_at');
            $table->unsignedBigInteger('expires_at');
            $table->text('quote_json');
            $table->char('content_hash', 64);
            $table->unique('order_id', 'tx_upgrade_credit_order_uq');
            $table->index(['user_id', 'id'], 'tx_upgrade_credit_user_idx');
            $table->foreign('installation_id', 'tx_upgrade_credit_installation_fk')
                ->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
            $table->foreign(['order_id', 'user_id'], 'tx_upgrade_credit_order_fk')
                ->references(['id', 'user_id'])->on('tx_order')->restrictOnDelete();
            $table->foreign(['subscription_id', 'user_id'], 'tx_upgrade_credit_subscription_fk')
                ->references(['id', 'user_id'])->on('tx_subscription')->restrictOnDelete();
            $table->foreign('terms_id', 'tx_upgrade_credit_terms_fk')
                ->references('id')->on('tx_subscription_terms')->restrictOnDelete();
            $table->foreign('period_id', 'tx_upgrade_credit_period_fk')
                ->references('id')->on('tx_usage_period')->restrictOnDelete();
            $table->foreign('offer_id', 'tx_upgrade_credit_offer_fk')
                ->references('id')->on('tx_plan_offer')->restrictOnDelete();
        });
        Schema::create('tx_upgrade_credit_source', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('quote_id');
            $table->unsignedBigInteger('order_payment_id');
            $table->unsignedBigInteger('amount_minor');
            $table->unique(['quote_id', 'order_payment_id'], 'tx_upgrade_source_quote_payment_uq');
            $table->index(['order_payment_id', 'id'], 'tx_upgrade_source_payment_idx');
            $table->foreign('quote_id', 'tx_upgrade_source_quote_fk')
                ->references('id')->on('tx_order_upgrade_credit')->restrictOnDelete();
            $table->foreign('order_payment_id', 'tx_upgrade_source_payment_fk')
                ->references('id')->on('tx_order_payment')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_order_upgrade_credit')->exists() || DB::table('tx_upgrade_credit_source')->exists()) {
            throw new RuntimeException('NATIVE_UPGRADE_CREDIT_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::drop('tx_upgrade_credit_source');
        Schema::drop('tx_order_upgrade_credit');
        if (Schema::hasTable('tx_usage_entry')) {
            Schema::table('tx_usage_entry', function (Blueprint $table): void {
                $table->dropIndex('tx_usage_entry_period_batch_idx');
            });
        }
    }
};
