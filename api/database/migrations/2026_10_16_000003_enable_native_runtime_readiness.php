<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tx_auth_snapshot', function (Blueprint $table): void {
            $table->index(['subscription_id', 'id'], 'tx_native_auth_sub_idx');
        });
        Schema::create('tx_native_runtime_activation', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('installation_id', 36)->unique();
            $table->string('business_key', 128)->unique();
            $table->string('runtime_version', 64);
            $table->char('request_hash', 64);
            $table->json('configuration_snapshot');
            $table->char('configuration_hash', 64);
            $table->unsignedBigInteger('authority_epoch');
            $table->unsignedBigInteger('activated_at');
        });
        Schema::create('tx_native_usage_grant', function (Blueprint $table): void {
            $table->unsignedBigInteger('grant_id')->primary();
            $table->char('installation_id', 36);
            $table->json('binding_snapshot');
            $table->char('binding_hash', 64);
            $table->char('authorization_digest', 64)->nullable();
            $table->unsignedBigInteger('created_at');
            $table->foreign('grant_id')->references('id')->on('tx_auth_snapshot')->restrictOnDelete();
        });
        Schema::create('tx_native_usage_reservation', function (Blueprint $table): void {
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('usage_period_id');
            $table->unsignedBigInteger('reserved_bytes');
            $table->unsignedBigInteger('created_at');
            $table->primary(['batch_id', 'usage_period_id'], 'tx_native_usage_reservation_pk');
            $table->index(['usage_period_id', 'batch_id'], 'tx_native_usage_reservation_period_idx');
            $table->foreign('batch_id')->references('id')->on('tx_usage_batch')->restrictOnDelete();
            $table->foreign('usage_period_id')->references('id')->on('tx_usage_period')->restrictOnDelete();
        });
        Schema::create('tx_native_runtime_outbox', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('event_key', 128)->unique();
            $table->string('kind', 32);
            $table->integer('user_id');
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('subscription_version');
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('attempts')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('completed_at')->nullable();
            $table->foreign(['subscription_id', 'user_id'])->references(['id', 'user_id'])->on('tx_subscription')->restrictOnDelete();
            $table->index(['status', 'id'], 'tx_native_runtime_outbox_scan_idx');
        });
    }

    public function down(): void
    {
        foreach (['tx_native_runtime_activation', 'tx_native_usage_grant', 'tx_native_usage_reservation', 'tx_native_runtime_outbox'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('NATIVE_RUNTIME_POPULATED_ROLLBACK_REFUSED');
            }
        }
        Schema::drop('tx_native_runtime_outbox');
        Schema::drop('tx_native_usage_reservation');
        Schema::drop('tx_native_usage_grant');
        Schema::drop('tx_native_runtime_activation');
        Schema::table('tx_auth_snapshot', function (Blueprint $table): void {
            $table->dropIndex('tx_native_auth_sub_idx');
        });
    }
};
