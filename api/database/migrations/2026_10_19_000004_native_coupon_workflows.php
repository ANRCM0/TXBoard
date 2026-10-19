<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tx_native_coupon')) Schema::create('tx_native_coupon', function (Blueprint $t): void {
            $t->bigIncrements('id'); $t->char('code_hash', 64)->unique(); $t->text('policy_json');
            $t->char('policy_hash', 64); $t->boolean('enabled'); $t->unsignedBigInteger('row_version');
            $t->unsignedInteger('used_total')->default(0); $t->unsignedBigInteger('created_at'); $t->unsignedBigInteger('updated_at');
        });
        if (!Schema::hasTable('tx_native_coupon_user')) Schema::create('tx_native_coupon_user', function (Blueprint $t): void {
            $t->unsignedBigInteger('coupon_id'); $t->integer('user_id'); $t->unsignedInteger('used_count');
            $t->primary(['coupon_id', 'user_id']);
            $t->foreign('coupon_id')->references('id')->on('tx_native_coupon')->restrictOnDelete();
            $t->foreign('user_id')->references('id')->on('tx_user')->restrictOnDelete();
        });
        if (!Schema::hasTable('tx_native_coupon_application')) Schema::create('tx_native_coupon_application', function (Blueprint $t): void {
            $t->integer('order_id')->primary(); $t->integer('user_id'); $t->unsignedBigInteger('coupon_id');
            $t->unsignedInteger('usage_sequence'); $t->unsignedInteger('user_sequence');
            $t->unique(['coupon_id', 'usage_sequence'], 'tx_coupon_usage_sequence_uq');
            $t->unique(['coupon_id', 'user_id', 'user_sequence'], 'tx_coupon_user_sequence_uq');
            $t->uuid('installation_id'); $t->mediumText('quote_json'); $t->char('content_hash', 64); $t->unsignedBigInteger('created_at');
            $t->foreign('order_id')->references('id')->on('tx_order')->restrictOnDelete();
            $t->foreign('user_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('coupon_id')->references('id')->on('tx_native_coupon')->restrictOnDelete();
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
        if (!Schema::hasTable('tx_native_coupon_request')) Schema::create('tx_native_coupon_request', function (Blueprint $t): void {
            $t->char('key_hash', 64)->primary(); $t->char('input_hash', 64); $t->uuid('installation_id');
            $t->integer('actor_id'); $t->string('action', 16); $t->text('response_json');
            $t->char('content_hash', 64); $t->unsignedBigInteger('created_at');
            $t->foreign('actor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        $tables = ['tx_native_coupon_request', 'tx_native_coupon_application', 'tx_native_coupon_user', 'tx_native_coupon'];
        foreach ($tables as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) throw new RuntimeException('NATIVE_COUPON_FORWARD_RECOVERY_REQUIRED');
        }
        foreach ($tables as $name) Schema::dropIfExists($name);
    }
};
