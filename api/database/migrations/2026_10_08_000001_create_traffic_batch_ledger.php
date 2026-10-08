<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_traffic_batch', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->string('batch_id', 80);
            $table->char('payload_hash', 64);
            $table->unsignedBigInteger('created_at');
            $table->unique(['server_id', 'batch_id'], 'uq_traffic_batch_server_batch');
            $table->index('created_at', 'idx_traffic_batch_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_traffic_batch');
    }
};
