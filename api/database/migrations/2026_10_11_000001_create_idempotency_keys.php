<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('operation', 32);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('status', 24);
            $table->string('trade_no', 64)->nullable();
            $table->json('result_snapshot')->nullable();
            $table->unsignedBigInteger('expires_at');
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
            $table->unique(['user_id', 'operation', 'key_hash'], 'uq_idempotency_user_op_key');
            $table->index(['expires_at', 'id'], 'idx_idempotency_expiration');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
