<?php

use App\Support\Database\NativeTableName;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(NativeTableName::runtime('v2_wallet_recharge'), function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('payment_id');
            $table->string('trade_no', 80)->unique();
            $table->char('request_key', 36);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->unsignedTinyInteger('status')->default(0);
            $table->string('callback_no', 191)->nullable();
            $table->unsignedBigInteger('paid_at')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
            $table->unique(['user_id', 'request_key'], 'uq_wallet_recharge_user_request');
            $table->unique(['payment_id', 'callback_no'], 'uq_wallet_recharge_payment_callback');
            $table->index(['user_id', 'created_at'], 'idx_wallet_recharge_user_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(NativeTableName::runtime('v2_wallet_recharge'));
    }
};
