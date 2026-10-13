<?php

use App\Services\Subscription\ShadowSchemaV1;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = new ShadowSchemaV1();
        $schema->create('tx_legacy_money_shadow_checkpoint', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('stream_id');
            $t->integer('legacy_user_id');
            $t->char('context_hash', 64);
            $t->char('currency', 3);
            $t->char('anchor_event_hash', 64);
            $t->unsignedBigInteger('anchor_wallet_minor');
            $t->unsignedBigInteger('anchor_commission_minor');
            $t->unsignedBigInteger('high_water');
            $t->char('high_event_hash', 64);
            $t->unsignedBigInteger('last_sequence');
            $t->char('last_event_hash', 64)->nullable();
            $t->char('last_after_hash', 64);
            $t->unsignedBigInteger('wallet_minor');
            $t->unsignedBigInteger('commission_minor');
            $t->char('content_hash', 64);
            $t->unique('stream_id', 'tx_money_shadow_stream_uq');
            $t->unique('legacy_user_id', 'tx_money_shadow_user_uq');
            $t->foreign('stream_id', 'tx_money_shadow_stream_fk')->references('id')
                ->on('tx_legacy_capture_shadow_stream')->restrictOnDelete();
        });
        $schema->create('tx_legacy_money_shadow_step', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('checkpoint_id');
            $t->unsignedBigInteger('capture_event_id');
            $t->unsignedBigInteger('sequence');
            $t->char('capture_event_hash', 64);
            $t->char('before_hash', 64);
            $t->char('after_hash', 64);
            $t->unsignedBigInteger('wallet_before');
            $t->unsignedBigInteger('wallet_after');
            $t->unsignedBigInteger('commission_before');
            $t->unsignedBigInteger('commission_after');
            $t->char('content_hash', 64);
            $t->unique('capture_event_id', 'tx_money_shadow_event_uq');
            $t->unique(['checkpoint_id', 'sequence'], 'tx_money_shadow_sequence_uq');
            $t->foreign('checkpoint_id', 'tx_money_shadow_checkpoint_fk')->references('id')
                ->on('tx_legacy_money_shadow_checkpoint')->restrictOnDelete();
            $t->foreign('capture_event_id', 'tx_money_shadow_event_fk')->references('id')
                ->on('tx_legacy_capture_shadow_event')->restrictOnDelete();
        });
        $schema->create('tx_legacy_money_shadow_posting', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('step_id');
            $t->unsignedInteger('line_no');
            $t->enum('purpose', ['user_wallet', 'capture_wallet_clearing', 'user_commission', 'capture_commission_clearing']);
            $t->enum('side', ['debit', 'credit']);
            $t->unsignedBigInteger('amount_minor');
            $t->char('content_hash', 64);
            $t->unique(['step_id', 'line_no'], 'tx_money_shadow_line_uq');
            $t->foreign('step_id', 'tx_money_shadow_step_fk')->references('id')
                ->on('tx_legacy_money_shadow_step')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $tables = ['tx_legacy_money_shadow_posting', 'tx_legacy_money_shadow_step', 'tx_legacy_money_shadow_checkpoint'];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('MONEY_SHADOW_POPULATED_ROLLBACK_REFUSED');
            }
        }
        foreach ($tables as $table) Schema::dropIfExists($table);
    }
};
