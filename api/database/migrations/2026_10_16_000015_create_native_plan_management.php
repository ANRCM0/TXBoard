<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_native_plan_request', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('installation_id', 36);
            $table->integer('actor_id');
            $table->integer('plan_id');
            $table->string('business_key', 64);
            $table->char('request_hash', 64);
            $table->json('result_snapshot');
            $table->char('result_hash', 64);
            $table->unsignedBigInteger('created_at');
            $table->unique(['installation_id', 'actor_id', 'business_key'], 'tx_native_plan_request_uq');
            $table->foreign('actor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('plan_id')->references('id')->on('tx_plan')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_native_plan_request')->exists()) {
            throw new RuntimeException('NATIVE_PLAN_REQUEST_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::drop('tx_native_plan_request');
    }
};
