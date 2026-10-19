<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_support_attachment', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('installation_id'); $t->integer('ticket_id'); $t->integer('uploader_id');
            $t->integer('message_id')->nullable(); $t->string('filename'); $t->string('media_type', 32);
            $t->unsignedBigInteger('size_bytes'); $t->char('sha256', 64); $t->string('status', 16);
            $t->unsignedBigInteger('received_bytes'); $t->unsignedInteger('next_chunk_index');
            $t->unsignedBigInteger('created_at'); $t->unsignedBigInteger('expires_at'); $t->char('content_hash', 64);
            $t->index(['installation_id', 'ticket_id', 'id'], 'tx_support_attachment_ticket_idx');
            $t->index(['installation_id', 'uploader_id', 'status', 'expires_at'], 'tx_support_attachment_pending_idx');
            $t->index(['status', 'expires_at', 'id'], 'tx_support_attachment_expiry_idx');
            $t->index(['message_id', 'id'], 'tx_support_attachment_message_idx');
            $t->foreign('ticket_id')->references('id')->on('tx_ticket')->restrictOnDelete();
            $t->foreign('uploader_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('message_id')->references('id')->on('tx_ticket_message')->restrictOnDelete();
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
        Schema::create('tx_support_attachment_chunk', function (Blueprint $t): void {
            $t->uuid('attachment_id'); $t->unsignedInteger('chunk_index'); $t->text('ciphertext');
            $t->char('sha256', 64); $t->unsignedInteger('size_bytes');
            $t->primary(['attachment_id', 'chunk_index'], 'tx_support_attachment_chunk_pk');
            $t->foreign('attachment_id')->references('id')->on('tx_support_attachment')->restrictOnDelete();
        });
        Schema::create('tx_support_attachment_request', function (Blueprint $t): void {
            $t->char('key_hash', 64)->primary(); $t->uuid('installation_id'); $t->integer('actor_id');
            $t->string('operation', 32); $t->char('input_hash', 64); $t->uuid('attachment_id'); $t->char('content_hash', 64);
            $t->unsignedBigInteger('created_at');
            $t->foreign('attachment_id')->references('id')->on('tx_support_attachment')->restrictOnDelete();
            $t->foreign('actor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $tables = ['tx_support_attachment_request', 'tx_support_attachment_chunk', 'tx_support_attachment'];
        foreach ($tables as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) throw new RuntimeException('NATIVE_SUPPORT_ATTACHMENTS_FORWARD_RECOVERY_REQUIRED');
        }
        foreach ($tables as $name) Schema::dropIfExists($name);
    }
};
