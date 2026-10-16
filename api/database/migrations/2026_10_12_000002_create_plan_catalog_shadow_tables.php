<?php

use App\Domains\Installation\SchemaReplayValidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLES = ['tx_plan_revision', 'tx_plan_offer', 'tx_plan_offer_availability',
        'tx_plan_catalog_shadow', 'tx_plan_catalog_checkpoint', 'tx_plan_catalog_exception'];

    public function up(): void
    {
        $schema = new SchemaReplayValidator();
        $schema->create('tx_plan_revision', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('plan_id');
            $table->unsignedBigInteger('revision_no');
            $table->string('schema_version', 64);
            $table->integer('group_id')->nullable();
            $table->unsignedBigInteger('quota_bytes')->nullable();
            $table->unsignedInteger('speed_limit_mbps')->nullable();
            $table->unsignedInteger('device_limit')->nullable();
            $table->json('reset_policy');
            $table->string('billing_timezone', 64);
            $table->json('source_snapshot');
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('published_at');
            $table->unsignedBigInteger('created_at');
            $table->unique(['plan_id', 'revision_no'], 'tx_revision_number_uq');
            $table->unique(['id', 'plan_id'], 'tx_revision_owner_uq');
        });
        $schema->create('tx_plan_offer', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('plan_id');
            $table->unsignedBigInteger('plan_revision_id');
            $table->string('offer_code', 32);
            $table->unsignedBigInteger('version_no');
            $table->char('currency', 3);
            $table->unsignedTinyInteger('currency_minor_exponent');
            $table->bigInteger('price_minor');
            $table->unsignedInteger('calendar_months')->nullable();
            $table->unsignedBigInteger('duration_seconds')->nullable();
            $table->enum('purchase_kind', ['subscription', 'perpetual', 'traffic_reset']);
            $table->json('calendar_policy');
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('created_at');
            $table->unique(['plan_id', 'offer_code', 'version_no'], 'tx_offer_version_uq');
            $table->foreign(['plan_revision_id', 'plan_id'], 'tx_offer_revision_fk')
                ->references(['id', 'plan_id'])->on('tx_plan_revision')->restrictOnDelete();
        });
        $schema->create('tx_plan_offer_availability', function (Blueprint $table) {
            $table->unsignedBigInteger('offer_id')->primary();
            $table->enum('status', ['shadow', 'superseded']);
            $table->boolean('show');
            $table->boolean('sell');
            $table->boolean('renew');
            $table->unsignedInteger('capacity_limit')->nullable();
            $this->mutableColumns($table);
            $table->foreign('offer_id', 'tx_availability_offer_fk')->references('id')->on('tx_plan_offer')->restrictOnDelete();
        });
        $schema->create('tx_plan_catalog_shadow', function (Blueprint $table) {
            $table->integer('legacy_plan_id')->primary();
            $table->unsignedBigInteger('current_revision_id');
            $table->char('source_hash', 64);
            $table->string('run_key', 64);
            $this->mutableColumns($table);
            $table->foreign(['current_revision_id', 'legacy_plan_id'], 'tx_catalog_revision_fk')
                ->references(['id', 'plan_id'])->on('tx_plan_revision')->restrictOnDelete();
        });
        $schema->create('tx_plan_catalog_checkpoint', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('run_key', 64)->unique();
            $table->enum('phase', ['processing', 'complete']);
            $table->integer('cursor_plan_id')->default(0);
            $table->integer('high_water_plan_id');
            $table->unsignedBigInteger('processed_count')->default(0);
            $table->json('details');
            $this->mutableColumns($table);
        });
        $schema->create('tx_plan_catalog_exception', function (Blueprint $table) {
            $table->integer('legacy_plan_id')->primary();
            $table->string('run_key', 64);
            $table->enum('state', ['unresolved', 'resolved']);
            $table->string('error_code', 64);
            $table->unsignedBigInteger('attempts');
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
        });
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new \RuntimeException('Plan catalog shadow contains data; restore a verified backup or fix forward.');
            }
        }
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function mutableColumns(Blueprint $table): void
    {
        $table->unsignedBigInteger('row_version')->default(1);
        $table->unsignedBigInteger('created_at');
        $table->unsignedBigInteger('updated_at');
    }
};
