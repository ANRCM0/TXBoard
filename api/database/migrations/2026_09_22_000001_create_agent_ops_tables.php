<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_agent_action', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 64)->unique();
            $table->unsignedBigInteger('admin_id')->index();
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->unsignedBigInteger('node_id')->index();
            $table->string('action', 64)->index();
            $table->string('risk_level', 16)->default('operate');
            $table->string('status', 24)->index();
            $table->text('input')->nullable();
            $table->text('result')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->unsignedInteger('approved_at')->nullable();
            $table->unsignedInteger('started_at')->nullable();
            $table->unsignedInteger('finished_at')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');

            $table->index(['node_id', 'status']);
            $table->index(['token_id', 'created_at']);
        });

        Schema::create('v2_agent_audit_log', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 64)->index();
            $table->unsignedBigInteger('admin_id')->index();
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->string('client_name', 128)->nullable();
            $table->string('tool', 128)->index();
            $table->string('risk_level', 16)->default('read');
            $table->string('target_type', 32)->nullable();
            $table->string('target_id', 64)->nullable()->index();
            $table->text('input_redacted')->nullable();
            $table->string('result_status', 24)->index();
            $table->text('result_summary')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('ip', 128)->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_agent_audit_log');
        Schema::dropIfExists('v2_agent_action');
    }
};
