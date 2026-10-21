<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tx_native_account_metadata_head')) Schema::create('tx_native_account_metadata_head', function (Blueprint $table): void {
            $table->integer('user_id')->primary(); $table->uuid('installation_id'); $table->unsignedBigInteger('version');
            $table->foreign('user_id')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
        if (!Schema::hasTable('tx_native_account_metadata_revision')) Schema::create('tx_native_account_metadata_revision', function (Blueprint $table): void {
            $table->integer('user_id'); $table->unsignedBigInteger('version'); $table->uuid('installation_id');
            $table->integer('actor_id'); $table->text('remarks')->nullable(); $table->char('content_hash', 64); $table->unsignedBigInteger('created_at');
            $table->primary(['user_id', 'version']);
            $table->foreign('user_id')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tx_native_account_metadata_revision') && DB::table('tx_native_account_metadata_revision')->exists()) throw new RuntimeException('NATIVE_IDENTITY_FORWARD_RECOVERY_REQUIRED');
        Schema::dropIfExists('tx_native_account_metadata_revision'); Schema::dropIfExists('tx_native_account_metadata_head');
    }
};
