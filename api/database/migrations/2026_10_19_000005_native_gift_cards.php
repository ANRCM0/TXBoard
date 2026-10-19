<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tx_native_gift_card')) Schema::create('tx_native_gift_card', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('installation_id'); $t->integer('issuer_id'); $t->integer('recipient_id')->nullable();
            $t->char('code_hash', 64)->unique(); $t->text('terms_json'); $t->char('terms_hash', 64);
            $t->string('state', 16); $t->unsignedBigInteger('row_version');
            foreach (['reserve', 'capture', 'credit', 'release'] as $kind) {
                $column = $t->unsignedBigInteger($kind.'_journal_id');
                if ($kind !== 'reserve') $column->nullable();
                $t->foreign([$kind.'_journal_id', 'installation_id', $kind === 'credit' ? 'recipient_id' : 'issuer_id'], 'tx_gift_'.$kind.'_ownership_fk')->references(['id', 'installation_id', 'user_id'])->on('tx_money_journal')->restrictOnDelete();
            }
            $t->unsignedBigInteger('expires_at'); $t->unsignedBigInteger('created_at'); $t->unsignedBigInteger('updated_at'); $t->unsignedBigInteger('closed_at')->nullable();
            $t->index(['state', 'expires_at'], 'tx_gift_expiry_idx'); $t->index(['issuer_id', 'created_at'], 'tx_gift_issuer_idx'); $t->index(['recipient_id', 'created_at'], 'tx_gift_recipient_idx');
            $t->foreign('issuer_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('recipient_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
        if (!Schema::hasTable('tx_native_gift_terminal')) Schema::create('tx_native_gift_terminal', function (Blueprint $t): void {
            $t->uuid('card_id')->primary(); $t->text('payload_json'); $t->char('content_hash', 64);
            $t->foreign('card_id')->references('id')->on('tx_native_gift_card')->restrictOnDelete();
        });
        if (!Schema::hasTable('tx_native_gift_request')) Schema::create('tx_native_gift_request', function (Blueprint $t): void {
            $t->char('key_hash', 64)->primary(); $t->char('input_hash', 64); $t->uuid('installation_id'); $t->integer('actor_id');
            $t->string('action', 16); $t->text('response_cipher'); $t->char('content_hash', 64); $t->unsignedBigInteger('created_at');
            $t->foreign('actor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        $tables = ['tx_native_gift_request', 'tx_native_gift_terminal', 'tx_native_gift_card'];
        foreach ($tables as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) throw new RuntimeException('NATIVE_GIFT_CARD_FORWARD_RECOVERY_REQUIRED');
        }
        foreach ($tables as $name) Schema::dropIfExists($name);
    }
};
