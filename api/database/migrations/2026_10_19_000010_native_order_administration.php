<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_native_order_admin_request', function (Blueprint $t): void {
            $t->bigIncrements('id'); $t->uuid('installation_id'); $t->integer('actor_id'); $t->integer('order_id');
            $t->string('action', 16); $t->char('key_hash', 64); $t->char('input_hash', 64);
            $t->text('payload_json'); $t->char('content_hash', 64); $t->unsignedBigInteger('created_at');
            $t->unique('key_hash', 'tx_order_admin_request_key_uq');
            $t->index(['installation_id', 'order_id', 'id'], 'tx_order_admin_audit_cursor_idx');
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
            $t->foreign('actor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('order_id')->references('id')->on('tx_order')->restrictOnDelete();
        });
    }

    public function down(): void { throw new RuntimeException('NATIVE_ORDER_ADMIN_FORWARD_RECOVERY_REQUIRED'); }
};
