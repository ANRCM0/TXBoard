<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('tx_native_identity_admin_request')) return;
        Schema::create('tx_native_identity_admin_request', function (Blueprint $table): void {
            $table->char('key_hash', 64)->primary(); $table->uuid('installation_id'); $table->integer('actor_id');
            $table->string('operation', 32); $table->char('input_hash', 64); $table->text('response_ciphertext');
            $table->char('content_hash', 64); $table->unsignedBigInteger('created_at');
            $table->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('tx_user')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tx_native_identity_admin_request') && DB::table('tx_native_identity_admin_request')->exists()) throw new RuntimeException('NATIVE_IDENTITY_FORWARD_RECOVERY_REQUIRED');
        Schema::dropIfExists('tx_native_identity_admin_request');
    }
};
