<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $identity = static function (Blueprint $table, string $column, int $length): void {
            $definition = $table->string($column, $length);
            if (DB::getDriverName() === 'mysql') {
                $definition->charset('ascii')->collation('ascii_bin');
            }
        };
        Schema::create('tx_money_account', function (Blueprint $table) use ($identity): void {
            $table->bigIncrements('id');
            $table->char('installation_id', 36);
            $table->integer('owner_id');
            $table->integer('user_id')->nullable();
            $identity($table, 'purpose', 32);
            $table->char('currency', 3);
            $table->string('normal_side', 6);
            $table->bigInteger('balance_minor')->default(0);
            $table->unsignedBigInteger('created_at');
            $table->unique(['installation_id', 'owner_id', 'purpose', 'currency'], 'tx_money_account_identity_uq');
            $table->foreign('user_id', 'tx_money_account_user_fk')->references('id')->on('tx_user')->restrictOnDelete();
        });
        Schema::create('tx_money_journal', function (Blueprint $table) use ($identity): void {
            $table->bigIncrements('id');
            $table->char('installation_id', 36);
            $table->integer('user_id');
            $identity($table, 'business_type', 32);
            $identity($table, 'business_ref', 128);
            $identity($table, 'idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->char('currency', 3);
            $table->unsignedBigInteger('occurred_at');
            $table->unsignedBigInteger('reverses_journal_id')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unique(['installation_id', 'user_id', 'business_type', 'business_ref', 'idempotency_key'],
                'tx_money_journal_business_uq');
            $table->unique('reverses_journal_id', 'tx_money_journal_reversal_uq');
            $table->foreign('user_id', 'tx_money_journal_user_fk')->references('id')->on('tx_user')->restrictOnDelete();
            $table->foreign('reverses_journal_id', 'tx_money_journal_reversal_fk')
                ->references('id')->on('tx_money_journal')->restrictOnDelete();
        });
        Schema::create('tx_money_posting', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('journal_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedTinyInteger('position');
            $table->string('side', 6);
            $table->unsignedBigInteger('amount_minor');
            $table->unique(['journal_id', 'position'], 'tx_money_posting_position_uq');
            $table->foreign('journal_id', 'tx_money_posting_journal_fk')
                ->references('id')->on('tx_money_journal')->restrictOnDelete();
            $table->foreign('account_id', 'tx_money_posting_account_fk')
                ->references('id')->on('tx_money_account')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['tx_money_posting', 'tx_money_journal', 'tx_money_account'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Native money evidence exists; fix forward or restore a verified backup.');
            }
        }
        Schema::dropIfExists('tx_money_posting');
        Schema::dropIfExists('tx_money_journal');
        Schema::dropIfExists('tx_money_account');
    }
};
