<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tx_ticket', function (Blueprint $table): void {
            $table->unsignedBigInteger('row_version')->default(1);
            $table->index(['user_id', 'status'], 'tx_ticket_user_status_idx');
        });
        Schema::table('tx_ticket_message', function (Blueprint $table): void {
            $table->string('author_kind', 16)->nullable();
            $table->index(['ticket_id', 'id'], 'tx_ticket_message_cursor_idx');
        });
        Schema::create('tx_support_request', function (Blueprint $table): void {
            $table->char('key_hash', 64)->primary();
            $table->uuid('installation_id');
            $table->integer('actor_id');
            $table->string('operation', 32);
            $table->char('input_hash', 64);
            $table->text('response_json');
            $table->char('response_hash', 64);
            $table->unsignedBigInteger('created_at');
            $table->foreign('actor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_support_request')->exists()
            || DB::table('tx_ticket_message')->whereNotNull('author_kind')->exists()) {
            throw new RuntimeException('NATIVE_SUPPORT_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::drop('tx_support_request');
        Schema::table('tx_ticket_message', function (Blueprint $table): void {
            $table->dropIndex('tx_ticket_message_cursor_idx');
            $table->dropColumn('author_kind');
        });
        Schema::table('tx_ticket', function (Blueprint $table): void {
            $table->dropIndex('tx_ticket_user_status_idx');
            $table->dropColumn('row_version');
        });
    }
};
