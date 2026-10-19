<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tx_native_referral_code')) Schema::create('tx_native_referral_code', function (Blueprint $t): void {
            $t->integer('user_id')->primary(); $t->uuid('installation_id'); $t->string('code', 35)->unique(); $t->unsignedBigInteger('created_at');
            $t->unique(['code', 'user_id'], 'tx_referral_code_owner_uq');
            $t->foreign('user_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
        if (!Schema::hasTable('tx_native_referral')) Schema::create('tx_native_referral', function (Blueprint $t): void {
            $t->integer('user_id')->primary(); $t->uuid('installation_id'); $t->integer('sponsor_id'); $t->string('invite_code', 35);
            $t->unsignedBigInteger('created_at'); $t->char('content_hash', 64);
            $t->foreign('user_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('sponsor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign(['invite_code', 'sponsor_id'], 'tx_referral_sponsor_fk')->references(['code', 'user_id'])->on('tx_native_referral_code')->restrictOnDelete();
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
        if (!Schema::hasTable('tx_native_commission_policy_head')) Schema::create('tx_native_commission_policy_head', function (Blueprint $t): void {
            $t->unsignedTinyInteger('id')->primary(); $t->uuid('installation_id'); $t->unsignedBigInteger('version');
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
        if (!Schema::hasTable('tx_native_commission_policy')) Schema::create('tx_native_commission_policy', function (Blueprint $t): void {
            $t->unsignedBigInteger('version')->primary(); $t->uuid('installation_id'); $t->text('payload_json'); $t->char('content_hash', 64); $t->unsignedBigInteger('created_at');
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
        if (!Schema::hasTable('tx_native_commission_order')) Schema::create('tx_native_commission_order', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('installation_id'); $t->integer('order_id')->unique(); $t->integer('buyer_id'); $t->integer('sponsor_id');
            $t->unsignedBigInteger('policy_version'); $t->foreign('policy_version')->references('version')->on('tx_native_commission_policy')->restrictOnDelete(); $t->text('snapshot_json'); $t->char('snapshot_hash', 64); $t->unsignedInteger('hold_seconds'); $t->string('state', 16); $t->unsignedBigInteger('created_at');
            $t->index(['state', 'created_at'], 'tx_commission_work_idx'); $t->index(['sponsor_id', 'created_at'], 'tx_commission_sponsor_idx');
            $t->foreign(['order_id', 'buyer_id'], 'tx_commission_order_owner_fk')->references(['id', 'user_id'])->on('tx_order')->restrictOnDelete();
            $t->foreign('buyer_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('sponsor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
        if (!Schema::hasTable('tx_native_commission_reward')) Schema::create('tx_native_commission_reward', function (Blueprint $t): void {
            $t->uuid('commission_id')->primary(); $t->uuid('installation_id'); $t->integer('sponsor_id'); $t->unsignedBigInteger('journal_id')->nullable();
            $t->text('payload_json'); $t->char('content_hash', 64);
            $t->foreign('commission_id')->references('id')->on('tx_native_commission_order')->restrictOnDelete();
            $t->foreign(['journal_id', 'installation_id', 'sponsor_id'], 'tx_commission_reward_owner_fk')->references(['id', 'installation_id', 'user_id'])->on('tx_money_journal')->restrictOnDelete();
            $t->foreign('sponsor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
        if (!Schema::hasTable('tx_native_commission_payout')) Schema::create('tx_native_commission_payout', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->uuid('installation_id'); $t->integer('user_id'); $t->text('terms_json'); $t->char('terms_hash', 64);
            $t->string('state', 16); $t->unsignedBigInteger('row_version'); $t->unsignedBigInteger('reserve_journal_id'); $t->unsignedBigInteger('decision_journal_id')->nullable();
            $t->integer('actor_id')->nullable(); $t->string('reason', 64)->nullable(); $t->unsignedBigInteger('created_at'); $t->unsignedBigInteger('closed_at')->nullable();
            $t->index(['user_id', 'created_at'], 'tx_commission_payout_user_idx'); $t->index(['state', 'created_at'], 'tx_commission_payout_state_idx');
            $t->foreign('user_id')->references('id')->on('tx_user')->restrictOnDelete(); $t->foreign('actor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
            foreach (['reserve', 'decision'] as $kind) $t->foreign([$kind.'_journal_id', 'installation_id', 'user_id'], 'tx_commission_'.$kind.'_owner_fk')->references(['id', 'installation_id', 'user_id'])->on('tx_money_journal')->restrictOnDelete();
        });
        if (!Schema::hasTable('tx_native_commission_payout_terminal')) Schema::create('tx_native_commission_payout_terminal', function (Blueprint $t): void {
            $t->uuid('payout_id')->primary(); $t->text('payload_json'); $t->char('content_hash', 64);
            $t->foreign('payout_id')->references('id')->on('tx_native_commission_payout')->restrictOnDelete();
        });
        if (!Schema::hasTable('tx_native_commission_request')) Schema::create('tx_native_commission_request', function (Blueprint $t): void {
            $t->char('key_hash', 64)->primary(); $t->char('input_hash', 64); $t->uuid('installation_id'); $t->integer('actor_id'); $t->string('action', 32);
            $t->text('response_json'); $t->char('content_hash', 64); $t->unsignedBigInteger('created_at');
            $t->foreign('actor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
    }
    public function down(): void
    {
        $tables = ['tx_native_commission_request', 'tx_native_commission_payout_terminal', 'tx_native_commission_payout',
            'tx_native_commission_reward', 'tx_native_commission_order', 'tx_native_commission_policy',
            'tx_native_commission_policy_head', 'tx_native_referral', 'tx_native_referral_code'];
        foreach ($tables as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) throw new RuntimeException('NATIVE_COMMISSION_FORWARD_RECOVERY_REQUIRED');
        }
        foreach ($tables as $name) Schema::dropIfExists($name);
    }
};
