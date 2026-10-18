<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tx_plugins', function (Blueprint $table): void {
            $table->unsignedInteger('module_schema_version')->default(0);
            $table->unsignedBigInteger('module_config_version')->default(1);
        });
        Schema::create('tx_module_deployment', function (Blueprint $table): void {
            $table->uuid('deployment_id')->primary();
            $table->uuid('installation_id');
            $table->string('module_code', 64)->index();
            $table->string('action', 16);
            $table->string('package_version', 80);
            $table->unsignedInteger('schema_version');
            $table->char('package_hash', 64);
            $table->char('request_hash', 64);
            $table->char('mutation_token', 64);
            $table->string('phase', 32);
            $table->longText('state_snapshot');
            $table->string('error_code', 80)->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
        });
        Schema::create('tx_module_event', function (Blueprint $table): void {
            $table->char('event_key', 64)->primary();
            $table->uuid('installation_id');
            $table->string('kind', 96);
            $table->string('business_ref', 128);
            $table->uuid('correlation_id');
            $table->longText('body');
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('occurred_at');
        });
        Schema::create('tx_module_delivery', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('event_key', 64);
            $table->string('module_code', 64);
            $table->string('package_version', 80);
            $table->char('package_hash', 64);
            $table->longText('configuration_ciphertext');
            $table->unsignedBigInteger('configuration_version');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->char('lease_token', 64)->nullable();
            $table->unsignedBigInteger('lease_until')->nullable();
            $table->unsignedBigInteger('next_attempt_at')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('completed_at')->nullable();
            $table->unique(['event_key', 'module_code'], 'tx_module_delivery_event_owner_uq');
            $table->index(['status', 'next_attempt_at', 'id'], 'tx_module_delivery_pending_idx');
            $table->foreign('event_key')->references('event_key')->on('tx_module_event')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_module_deployment')->exists() || DB::table('tx_module_event')->exists()
            || DB::table('tx_plugins')->where('module_schema_version', '>', 0)->exists()) {
            throw new RuntimeException('MODULE_RUNTIME_EVIDENCE_REQUIRES_RETENTION');
        }
        Schema::drop('tx_module_delivery');
        Schema::drop('tx_module_event');
        Schema::drop('tx_module_deployment');
        Schema::table('tx_plugins', fn (Blueprint $table) => $table->dropColumn(['module_schema_version', 'module_config_version']));
    }
};
