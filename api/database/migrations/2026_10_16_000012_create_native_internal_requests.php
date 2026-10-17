<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tx_internal_request', function (Blueprint $table): void {
            $table->char('key_hash', 64)->primary();
            $table->char('input_hash', 64);
            $table->string('operation', 128);
            $table->integer('actor_id');
            $table->string('state', 16);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_json')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
            $table->foreign('actor_id', 'tx_internal_request_actor_fk')
                ->references('id')->on('tx_user')->restrictOnDelete();
        });
        Schema::create('tx_internal_audit', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('execution_id', 36)->unique();
            $table->string('request_id', 64);
            $table->string('service_id', 64);
            $table->unsignedBigInteger('service_epoch');
            $table->integer('actor_id')->nullable();
            $table->string('operation', 128);
            $table->string('security_domain', 8);
            $table->string('state', 16);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 96)->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('completed_at')->nullable();
            $table->index(['actor_id', 'created_at']);
            $table->foreign('actor_id', 'tx_internal_audit_actor_fk')
                ->references('id')->on('tx_user')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_internal_request')->exists() || DB::table('tx_internal_audit')->exists()) {
            throw new RuntimeException('NATIVE_INTERNAL_REQUESTS_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::dropIfExists('tx_internal_audit');
        Schema::dropIfExists('tx_internal_request');
    }
};
