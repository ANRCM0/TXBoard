<?php

use App\Support\Database\NativeTableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Both tables are independent from billing and admin action logs.
        Schema::create(NativeTableName::runtime('v2_access_audit_rule'), function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('match_type', 24);
            $table->text('match_value');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create(NativeTableName::runtime('v2_access_audit_event'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('user_id');
            $table->char('event_id', 32);
            $table->string('target', 255);
            $table->string('target_ip', 64)->nullable();
            $table->string('source_ip', 64)->nullable();
            $table->boolean('matched');
            $table->unsignedInteger('created_at');
            $table->unique(['server_id', 'event_id'], 'uq_access_audit_node_event');
            $table->index(['created_at', 'id'], 'idx_access_audit_created');
            $table->index(['server_id', 'created_at'], 'idx_access_audit_server_created');
            $table->index(['user_id', 'created_at'], 'idx_access_audit_user_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(NativeTableName::runtime('v2_access_audit_event'));
        Schema::dropIfExists(NativeTableName::runtime('v2_access_audit_rule'));
    }
};
