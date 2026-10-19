<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tx_ticket', function (Blueprint $table): void {
            $table->integer('assigned_admin_id')->nullable();
            $table->index(['assigned_admin_id', 'status', 'updated_at'], 'tx_ticket_assignment_idx');
            $table->foreign('assigned_admin_id')->references('id')->on('tx_user')->restrictOnDelete();
        });
        Schema::create('tx_support_audit', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('installation_id');
            $table->integer('ticket_id');
            $table->unsignedBigInteger('row_version');
            $table->text('payload_json');
            $table->char('content_hash', 64);
            $table->unique(['ticket_id', 'row_version'], 'tx_support_audit_version_unique');
            $table->index(['installation_id', 'ticket_id', 'id'], 'tx_support_audit_cursor_idx');
            $table->foreign('ticket_id')->references('id')->on('tx_ticket')->restrictOnDelete();
            $table->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('tx_support_audit') && DB::table('tx_support_audit')->exists())
            || (Schema::hasColumn('tx_ticket', 'assigned_admin_id') && DB::table('tx_ticket')->whereNotNull('assigned_admin_id')->exists())) {
            throw new RuntimeException('NATIVE_SUPPORT_ASSIGNMENT_FORWARD_RECOVERY_REQUIRED');
        }
        Schema::dropIfExists('tx_support_audit');
        if (Schema::hasColumn('tx_ticket', 'assigned_admin_id')) Schema::table('tx_ticket', function (Blueprint $table): void {
            $table->dropForeign(['assigned_admin_id']);
            $table->dropIndex('tx_ticket_assignment_idx');
            $table->dropColumn('assigned_admin_id');
        });
    }
};
