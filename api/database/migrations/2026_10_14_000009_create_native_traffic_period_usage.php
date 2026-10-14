<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tx_native_traffic_period_usage', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('reset_count');
            $table->unsignedBigInteger('raw_upload')->default(0);
            $table->unsignedBigInteger('raw_download')->default(0);
            $table->unsignedBigInteger('billed_upload')->default(0);
            $table->unsignedBigInteger('billed_download')->default(0);
            $table->unsignedBigInteger('updated_at');
            $table->unique(['user_id', 'reset_count'], 'uq_native_traffic_user_period');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tx_native_traffic_period_usage') && DB::table('tx_native_traffic_period_usage')->exists()) {
            throw new RuntimeException('NATIVE_TRAFFIC_PERIOD_USAGE_NOT_EMPTY');
        }
        Schema::dropIfExists('tx_native_traffic_period_usage');
    }
};
