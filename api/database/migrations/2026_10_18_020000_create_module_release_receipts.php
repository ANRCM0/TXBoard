<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tx_module_release', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('installation_id');
            $table->unsignedInteger('version');
            $table->string('business_key', 128);
            $table->char('previous_fingerprint', 64)->nullable();
            $table->char('composition_fingerprint', 64);
            $table->longText('composition_snapshot');
            $table->char('request_hash', 64);
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('created_at');
            $table->unique(['installation_id', 'version'], 'tx_module_release_version_uq');
            $table->unique(['installation_id', 'business_key'], 'tx_module_release_key_uq');
            $table->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_module_release')->exists()) throw new RuntimeException('MODULE_RELEASE_EVIDENCE_REQUIRES_RETENTION');
        Schema::drop('tx_module_release');
    }
};
