<?php

use App\Domains\Installation\SchemaReplayValidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLES = ['tx_commerce_shadow_money_account', 'tx_commerce_shadow_money_journal', 'tx_commerce_shadow_money_posting',
        'tx_commerce_shadow_user_opening', 'tx_commerce_shadow_order', 'tx_commerce_shadow_checkpoint', 'tx_commerce_shadow_exception'];

    public function up(): void
    {
        $schema = new SchemaReplayValidator();
        $schema->create('tx_commerce_shadow_money_account', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->enum('owner_type', ['user', 'system']);
            $table->unsignedBigInteger('owner_id');
            $table->enum('purpose', ['wallet', 'commission', 'migration_wallet_clearing', 'migration_commission_clearing']);
            $table->char('currency', 3);
            $table->enum('normal_side', ['debit', 'credit']);
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('created_at');
            $table->unique(['owner_type', 'owner_id', 'purpose', 'currency'], 'tx_commerce_shadow_money_account_business_uq');
            $table->unique(['id', 'currency'], 'tx_commerce_shadow_money_account_currency_uq');
        });
        $schema->create('tx_commerce_shadow_money_journal', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->enum('business_type', ['legacy_wallet_opening', 'legacy_commission_opening']);
            $table->string('business_ref', 96);
            $table->string('idempotency_key', 64);
            $table->char('currency', 3);
            $table->enum('status', ['posted']);
            $table->json('source_snapshot');
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('occurred_at');
            $table->unsignedBigInteger('posted_at');
            $table->unsignedBigInteger('reverses_journal_id')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unique(['business_type', 'business_ref', 'idempotency_key'], 'tx_commerce_shadow_money_journal_business_uq');
            $table->unique(['id', 'currency'], 'tx_commerce_shadow_money_journal_currency_uq');
            $table->foreign('reverses_journal_id', 'tx_commerce_shadow_money_journal_reversal_fk')->references('id')->on('tx_commerce_shadow_money_journal')->restrictOnDelete();
        });
        $schema->create('tx_commerce_shadow_money_posting', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('journal_id');
            $table->unsignedInteger('line_no');
            $table->unsignedBigInteger('account_id');
            $table->char('currency', 3);
            $table->enum('side', ['debit', 'credit']);
            $table->bigInteger('amount_minor');
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('created_at');
            $table->unique(['journal_id', 'line_no'], 'tx_commerce_shadow_money_posting_line_uq');
            $table->foreign(['journal_id', 'currency'], 'tx_commerce_shadow_money_posting_journal_fk')->references(['id', 'currency'])->on('tx_commerce_shadow_money_journal')->restrictOnDelete();
            $table->foreign(['account_id', 'currency'], 'tx_commerce_shadow_money_posting_account_fk')->references(['id', 'currency'])->on('tx_commerce_shadow_money_account')->restrictOnDelete();
        });
        $schema->create('tx_commerce_shadow_user_opening', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_user_id');
            $table->char('currency', 3);
            $table->string('run_key', 64);
            $table->string('schema_version', 64);
            $table->json('source_snapshot');
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('wallet_journal_id')->nullable();
            $table->unsignedBigInteger('commission_journal_id')->nullable();
            $table->unsignedBigInteger('captured_at');
            $table->unsignedBigInteger('created_at');
            $table->unique(['legacy_user_id', 'currency'], 'tx_commerce_shadow_user_currency_uq');
            $table->foreign(['wallet_journal_id', 'currency'], 'tx_commerce_shadow_wallet_journal_fk')->references(['id', 'currency'])->on('tx_commerce_shadow_money_journal')->restrictOnDelete();
            $table->foreign(['commission_journal_id', 'currency'], 'tx_commerce_shadow_commission_journal_fk')->references(['id', 'currency'])->on('tx_commerce_shadow_money_journal')->restrictOnDelete();
        });
        $schema->create('tx_commerce_shadow_order', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('legacy_order_id')->unique();
            $table->unsignedBigInteger('legacy_user_id');
            $table->char('currency', 3);
            $table->string('run_key', 64);
            $table->string('schema_version', 64);
            $table->enum('historical_terms', ['legacy_snapshot_unknown']);
            $table->json('source_snapshot');
            $table->json('amount_evidence');
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('captured_at');
            $table->unsignedBigInteger('created_at');
        });
        $schema->create('tx_commerce_shadow_checkpoint', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('run_key', 64)->unique();
            $table->enum('phase', ['processing', 'complete']);
            $table->unsignedBigInteger('cursor_user_id')->default(0);
            $table->unsignedBigInteger('cursor_order_id')->default(0);
            $table->unsignedBigInteger('high_water_user_id');
            $table->unsignedBigInteger('high_water_order_id');
            $table->unsignedBigInteger('processed_count')->default(0);
            $table->json('details');
            $table->unsignedBigInteger('row_version')->default(1);
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
        });
        $schema->create('tx_commerce_shadow_exception', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->enum('aggregate_type', ['user', 'order']);
            $table->unsignedBigInteger('legacy_id');
            $table->char('currency', 3);
            $table->string('run_key', 64);
            $table->enum('state', ['unresolved', 'resolved']);
            $table->string('error_code', 64);
            $table->unsignedBigInteger('attempts');
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
            $table->unique(['aggregate_type', 'legacy_id', 'currency'], 'tx_commerce_shadow_exception_business_uq');
        });
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new \RuntimeException('Commerce shadow contains data; restore a verified backup or fix forward.');
            }
        }
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
