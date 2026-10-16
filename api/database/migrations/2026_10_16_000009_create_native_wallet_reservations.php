<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_wallet_order_reservation', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('installation_id', 36);
            $table->integer('user_id');
            $table->integer('order_id');
            $table->unsignedBigInteger('purchase_terms_id');
            $table->char('purchase_content_hash', 64);
            $table->string('request_key', 128);
            $table->char('key_hash', 64);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->char('content_hash', 64);
            $table->string('status', 16);
            $table->unsignedBigInteger('reserve_journal_id');
            $table->unsignedBigInteger('capture_journal_id')->nullable();
            $table->unsignedBigInteger('release_journal_id')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('captured_at')->nullable();
            $table->unsignedBigInteger('released_at')->nullable();
            $table->unique('order_id', 'tx_wallet_order_reservation_order_uq');
            $table->unique(['installation_id', 'user_id', 'key_hash'], 'tx_wallet_order_reservation_key_uq');
            $table->unique(['id', 'installation_id', 'user_id', 'order_id'], 'tx_wallet_reservation_owner_uq');
            $table->foreign(['order_id', 'user_id'], 'tx_wallet_reservation_order_fk')
                ->references(['id', 'user_id'])->on('tx_order')->restrictOnDelete();
            $table->foreign('installation_id', 'tx_wallet_reservation_installation_fk')
                ->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
            $table->foreign('purchase_terms_id', 'tx_wallet_reservation_terms_fk')
                ->references('id')->on('tx_order_purchase_terms')->restrictOnDelete();
            foreach (['reserve', 'capture', 'release'] as $kind) {
                $table->foreign([$kind.'_journal_id', 'installation_id', 'user_id'], 'tx_wallet_reservation_'.$kind.'_fk')
                    ->references(['id', 'installation_id', 'user_id'])->on('tx_money_journal')->restrictOnDelete();
            }
        });
        foreach (['tx_order_payment', 'tx_billing_work'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->unsignedBigInteger('wallet_reservation_id')->nullable();
                $table->unique('wallet_reservation_id', $name.'_wallet_reservation_uq');
                $table->foreign(['wallet_reservation_id', 'installation_id', 'user_id', 'order_id'], $name.'_wallet_owner_fk')
                    ->references(['id', 'installation_id', 'user_id', 'order_id'])
                    ->on('tx_wallet_order_reservation')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('tx_wallet_order_reservation')->exists()) {
            throw new RuntimeException('NATIVE_WALLET_POPULATED_ROLLBACK_REFUSED');
        }
        foreach (['tx_billing_work', 'tx_order_payment'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropForeign(DB::getDriverName() === 'sqlite'
                    ? ['wallet_reservation_id', 'installation_id', 'user_id', 'order_id'] : $name.'_wallet_owner_fk');
                $table->dropUnique($name.'_wallet_reservation_uq');
                $table->dropColumn('wallet_reservation_id');
            });
        }
        Schema::dropIfExists('tx_wallet_order_reservation');
    }
};
