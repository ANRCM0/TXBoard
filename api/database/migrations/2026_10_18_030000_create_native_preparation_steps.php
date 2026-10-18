<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tx_native_preparation_step', function (Blueprint $table): void {
            $table->uuid('installation_id');
            $table->string('owner', 32);
            $table->uuid('business_key');
            $table->char('request_hash', 64);
            $table->longText('result_snapshot');
            $table->char('result_hash', 64);
            $table->unsignedBigInteger('created_at');
            $table->primary(['installation_id', 'owner']);
            $table->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_native_preparation_step')->exists()) throw new RuntimeException('NATIVE_PREPARATION_EVIDENCE_REQUIRES_RETENTION');
        Schema::drop('tx_native_preparation_step');
    }
};
