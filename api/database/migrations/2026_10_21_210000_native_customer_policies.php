<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_native_customer_policy_head', function (Blueprint $table): void {
            $table->integer('user_id')->primary(); $table->uuid('installation_id'); $table->unsignedBigInteger('version');
            $table->foreign('user_id')->references('id')->on('tx_user')->restrictOnDelete();
        });
        Schema::create('tx_native_customer_policy_revision', function (Blueprint $table): void {
            $table->bigIncrements('id'); $table->uuid('installation_id'); $table->integer('user_id'); $table->unsignedBigInteger('version');
            $table->integer('actor_id'); $table->longText('payload_json'); $table->char('content_hash', 64); $table->unsignedBigInteger('created_at');
            $table->unique(['user_id', 'version'], 'tx_customer_policy_user_version_uq');
            $table->foreign('user_id')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('tx_user')->restrictOnDelete();
        });
        Schema::create('tx_native_customer_policy_request', function (Blueprint $table): void {
            $table->char('key_hash', 64)->primary(); $table->uuid('installation_id'); $table->integer('actor_id'); $table->integer('user_id');
            $table->char('request_hash', 64); $table->longText('request_json'); $table->longText('result_json'); $table->char('content_hash', 64);
        });
        Schema::create('tx_native_customer_price', function (Blueprint $table): void {
            $table->integer('order_id')->primary(); $table->uuid('installation_id'); $table->integer('user_id');
            $table->unsignedBigInteger('policy_version'); $table->longText('payload_json'); $table->char('content_hash', 64);
            $table->foreign('order_id')->references('id')->on('tx_order')->restrictOnDelete();
            $table->index(['user_id', 'order_id'], 'tx_customer_price_user_order_idx');
        });
        Schema::create('tx_native_first_paid_claim', function (Blueprint $table): void {
            $table->integer('user_id')->primary(); $table->uuid('installation_id'); $table->integer('order_id');
            $table->unsignedBigInteger('order_payment_id')->unique(); $table->longText('payload_json'); $table->char('content_hash', 64);
            $table->foreign('user_id')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('order_id')->references('id')->on('tx_order')->restrictOnDelete();
            $table->foreign('order_payment_id')->references('id')->on('tx_order_payment')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['tx_native_customer_policy_revision', 'tx_native_customer_policy_request', 'tx_native_customer_price', 'tx_native_first_paid_claim'] as $name) {
            if (DB::table($name)->exists()) throw new RuntimeException('NATIVE_BILLING_FORWARD_RECOVERY_REQUIRED');
        }
        foreach (['tx_native_first_paid_claim', 'tx_native_customer_price', 'tx_native_customer_policy_request', 'tx_native_customer_policy_revision', 'tx_native_customer_policy_head'] as $name) Schema::dropIfExists($name);
    }
};
