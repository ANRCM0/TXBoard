<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tx_internal_readonly_nonces', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('kid', 64);
            $table->char('jti', 32);
            $table->unsignedBigInteger('expires_at')->index();
            $table->unique(['kid', 'jti']);
        });
        Schema::create('tx_internal_readonly_windows', function (Blueprint $table) {
            $table->string('service', 32);
            $table->unsignedBigInteger('window_start');
            $table->unsignedInteger('requests');
            $table->unsignedBigInteger('expires_at')->index();
            $table->primary(['service', 'window_start']);
        });
        Schema::create('tx_internal_readonly_audits', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('service', 32);
            $table->string('operation', 64);
            $table->unsignedBigInteger('actor_id');
            $table->uuid('request_id');
            $table->unsignedSmallInteger('status');
            $table->string('error_code', 64)->nullable();
            $table->unsignedBigInteger('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tx_internal_readonly_audits');
        Schema::dropIfExists('tx_internal_readonly_windows');
        Schema::dropIfExists('tx_internal_readonly_nonces');
    }
};
