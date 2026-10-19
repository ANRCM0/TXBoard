<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_native_order_event', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->integer('order_id');
            $table->unsignedBigInteger('sequence');
            $table->string('type', 32);
            $table->unsignedTinyInteger('status');
            $table->unsignedBigInteger('occurred_at');
            $table->unique(['order_id', 'sequence'], 'tx_native_order_event_sequence_uq');
            $table->unique(['order_id', 'type'], 'tx_native_order_event_type_uq');
            $table->foreign('order_id', 'tx_native_order_event_order_fk')
                ->references('id')->on('tx_order')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_native_order_event')->exists()) {
            throw new RuntimeException('NATIVE_ORDER_EVENTS_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::drop('tx_native_order_event');
    }
};
