<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_agent_support_reply_request', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 64)->unique();
            $table->unsignedBigInteger('ticket_id')->index();
            $table->unsignedBigInteger('admin_id')->index();
            $table->unsignedBigInteger('token_id')->index();
            $table->unsignedBigInteger('last_message_id');
            $table->string('status', 24)->index();
            $table->text('message');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedInteger('approved_at')->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');
            $table->index(['ticket_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_agent_support_reply_request');
    }
};
