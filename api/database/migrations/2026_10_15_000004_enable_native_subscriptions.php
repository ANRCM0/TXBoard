<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tx_subscription', function (Blueprint $table): void {
            $table->string('authority_version', 64)->nullable();
            $table->char('installation_id', 36)->nullable();
            $table->index(['user_id', 'id'], 'tx_sub_user_id_idx');
        });
        Schema::table('tx_subscription_terms', function (Blueprint $table): void {
            $table->string('schema_version', 64)->nullable();
            $table->integer('source_order_id')->nullable();
            $table->json('source_snapshot')->nullable();
            $table->foreign('plan_revision_id', 'tx_native_terms_revision_fk')
                ->references('id')->on('tx_plan_revision')->restrictOnDelete();
            $table->foreign('source_order_id', 'tx_native_terms_order_fk')
                ->references('id')->on('tx_order')->restrictOnDelete();
        });
        Schema::create('tx_subscription_operation', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->integer('user_id');
            $table->unsignedBigInteger('subscription_id');
            $table->string('business_key', 128);
            $table->string('kind', 32);
            $table->integer('order_id')->nullable();
            $table->char('request_hash', 64);
            $table->json('request_snapshot');
            $table->json('result_snapshot');
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('created_at');
            $table->unique(['user_id', 'business_key'], 'tx_sub_operation_business_uq');
            $table->unique('order_id', 'tx_sub_operation_order_uq');
            $table->foreign(['subscription_id', 'user_id'], 'tx_sub_operation_owner_fk')
                ->references(['id', 'user_id'])->on('tx_subscription')->restrictOnDelete();
            $table->foreign('order_id', 'tx_sub_operation_order_fk')
                ->references('id')->on('tx_order')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_subscription_operation')->exists()
            || DB::table('tx_subscription')->whereNotNull('authority_version')->orWhereNotNull('installation_id')->exists()
            || DB::table('tx_subscription_terms')->whereNotNull('schema_version')
                ->orWhereNotNull('source_order_id')->orWhereNotNull('source_snapshot')->orWhereNotNull('plan_revision_id')->exists()
            || DB::table('tx_order')->where('purchase_terms_version', 'tx-core.purchase-terms/v2')->exists()) {
            throw new RuntimeException('NATIVE_SUBSCRIPTION_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::drop('tx_subscription_operation');
        Schema::table('tx_subscription_terms', function (Blueprint $table): void {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['plan_revision_id'] : 'tx_native_terms_revision_fk');
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['source_order_id'] : 'tx_native_terms_order_fk');
            $table->dropColumn(['schema_version', 'source_order_id', 'source_snapshot']);
        });
        Schema::table('tx_subscription', function (Blueprint $table): void {
            $table->dropIndex('tx_sub_user_id_idx');
            $table->dropColumn(['authority_version', 'installation_id']);
            if (DB::getDriverName() === 'sqlite') {
                $table->enum('status', ['pending', 'active', 'suspended', 'expired', 'revoked'])->change();
            }
        });
    }
};
