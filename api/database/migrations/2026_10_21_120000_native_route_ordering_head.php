<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tx_native_route_ordering_head')) {
            Schema::create('tx_native_route_ordering_head', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tx_native_route_ordering_head') && DB::table('tx_native_route_ordering_head')->exists()) {
            throw new RuntimeException('NATIVE_ROUTE_ORDERING_FORWARD_RECOVERY_REQUIRED');
        }
        Schema::dropIfExists('tx_native_route_ordering_head');
    }
};
