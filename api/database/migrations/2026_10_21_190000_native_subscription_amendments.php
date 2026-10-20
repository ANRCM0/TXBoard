<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('tx_native_subscription_amendment')) return;
        Schema::create('tx_native_subscription_amendment', function (Blueprint $table): void {
            $table->bigIncrements('id'); $table->uuid('installation_id'); $table->integer('actor_id'); $table->integer('user_id');
            $table->string('business_key', 128); $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('terms_id'); $table->unsignedBigInteger('transition_id');
            $table->char('request_hash', 64); $table->longText('request_snapshot'); $table->longText('source_snapshot');
            $table->longText('result_snapshot'); $table->char('content_hash', 64); $table->unsignedBigInteger('created_at');
            $table->unique(['installation_id', 'actor_id', 'business_key'], 'tx_native_amend_actor_key_uq');
            $table->unique('terms_id', 'tx_native_amend_terms_uq');
            $table->index(['user_id', 'id'], 'tx_native_amend_user_id_idx');
            $table->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('subscription_id')->references('id')->on('tx_subscription')->restrictOnDelete();
            $table->foreign('terms_id')->references('id')->on('tx_subscription_terms')->restrictOnDelete();
            $table->foreign('transition_id')->references('id')->on('tx_subscription_terms_transition')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tx_native_subscription_amendment') && DB::table('tx_native_subscription_amendment')->exists()) {
            throw new RuntimeException('NATIVE_SUBSCRIPTION_FORWARD_RECOVERY_REQUIRED');
        }
        Schema::dropIfExists('tx_native_subscription_amendment');
    }
};
