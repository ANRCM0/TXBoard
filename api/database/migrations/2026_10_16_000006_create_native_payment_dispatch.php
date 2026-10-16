<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_plugin_package_fence', function (Blueprint $table): void {
            $table->string('plugin_code')->primary();
            $table->char('mutation_token', 64)->nullable();
        });
        Schema::table('tx_plugins', function (Blueprint $table): void {
            $table->char('native_payment_mutation_token', 64)->nullable();
        });
        Schema::create('tx_payment_dispatch', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('attempt_id');
            $table->char('installation_id', 36);
            $table->integer('user_id');
            $table->string('intent_type', 16)->default('purchase');
            $table->unsignedBigInteger('recharge_id')->nullable();
            $table->integer('order_id')->nullable();
            $table->integer('payment_id');
            $table->string('plugin_code');
            $table->string('plugin_version', 50);
            $table->string('contract_version', 64);
            $table->string('provider', 32);
            $table->char('provider_order_ref', 36);
            $table->string('callback_uuid', 128);
            $table->text('snapshot_ciphertext');
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('last_queried_at')->nullable();
            $table->unique('attempt_id', 'tx_payment_dispatch_attempt_uq');
            $table->unique(['installation_id', 'provider_order_ref'], 'tx_payment_dispatch_order_uq');
            $table->index('provider_order_ref', 'tx_payment_dispatch_reference_idx');
            $table->index('plugin_code', 'tx_payment_dispatch_plugin_idx');
            $table->foreign('plugin_code', 'tx_payment_dispatch_plugin_fk')
                ->references('code')->on('tx_plugins')->restrictOnDelete();
            $table->foreign(['attempt_id', 'installation_id', 'user_id', 'order_id', 'payment_id'], 'tx_payment_dispatch_owner_fk')
                ->references(['id', 'installation_id', 'user_id', 'order_id', 'payment_id'])
                ->on('tx_payment_attempt')->restrictOnDelete();
            $table->foreign(['attempt_id', 'installation_id', 'user_id', 'payment_id'], 'tx_payment_dispatch_financial_owner_fk')
                ->references(['id', 'installation_id', 'user_id', 'payment_id'])
                ->on('tx_payment_attempt')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_payment_dispatch')->exists()) {
            throw new RuntimeException('NATIVE_PAYMENT_DISPATCH_POPULATED_ROLLBACK_REFUSED');
        }
        if (DB::table('tx_plugin_package_fence')->whereNotNull('mutation_token')->exists()) {
            throw new RuntimeException('PLUGIN_PACKAGE_MUTATION_ROLLBACK_REFUSED');
        }
        Schema::drop('tx_payment_dispatch');
        Schema::table('tx_plugins', fn (Blueprint $table) => $table->dropColumn('native_payment_mutation_token'));
        Schema::drop('tx_plugin_package_fence');
    }
};
