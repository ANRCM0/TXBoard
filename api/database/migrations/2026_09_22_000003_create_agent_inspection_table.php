<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tx_agent_inspection', function (Blueprint $table) {
            $table->id();
            $table->string('inspection_id', 64)->unique();
            $table->string('source', 24)->default('schedule')->index();
            $table->string('status', 16)->index();
            $table->text('summary')->nullable();
            $table->longText('findings')->nullable();
            $table->unsignedInteger('started_at');
            $table->unsignedInteger('finished_at');
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at');

            $table->index(['status', 'created_at']);
            $table->index(['source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tx_agent_inspection');
    }
};
