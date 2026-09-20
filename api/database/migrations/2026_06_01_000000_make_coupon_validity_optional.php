<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A coupon may be valid indefinitely. The admin UI already renders that state
 * ("不限") and omits both fields when the operator leaves the pickers empty,
 * but the columns were NOT NULL so those coupons could never be created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_coupon', function (Blueprint $table) {
            $table->integer('started_at')->nullable()->change();
            $table->integer('ended_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('v2_coupon', function (Blueprint $table) {
            $table->integer('started_at')->nullable(false)->change();
            $table->integer('ended_at')->nullable(false)->change();
        });
    }
};
