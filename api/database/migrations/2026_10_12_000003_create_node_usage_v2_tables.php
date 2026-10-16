<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Domains\Installation\SchemaReplayValidator;

return new class extends Migration {
    private const TABLES = ['tx_node_usage_source', 'tx_tariff_revision', 'tx_auth_snapshot', 'tx_usage_batch', 'tx_usage_entry', 'tx_usage_outbox', 'tx_node_usage_stat'];

    public function up(): void
    {
        $schema = new SchemaReplayValidator();
        $schema->create('tx_node_usage_source', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('node_id'); $t->unsignedBigInteger('source_epoch');
            $t->string('installation_ref', 80); $t->string('core_instance_id', 80);
            $t->unsignedBigInteger('receipt_generation'); $t->boolean('current')->default(true);
            $t->json('recovery_history')->nullable();
            $t->unsignedBigInteger('created_at');
            $t->unique(['node_id', 'source_epoch'], 'tx_usage_source_identity_uq');
            $t->unique(['node_id', 'installation_ref'], 'tx_usage_source_install_uq');
            // Deliberately no FK to legacy Server: tombstones survive its lifecycle.
        });
        $schema->create('tx_tariff_revision', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('node_id');
            $t->unsignedBigInteger('numerator'); $t->unsignedBigInteger('denominator');
            $t->unsignedBigInteger('effective_from'); $t->unsignedBigInteger('effective_until');
            $t->string('rounding_revision', 40)->default('floor-per-direction-v1');
            $t->char('content_hash', 64); $t->unsignedBigInteger('created_at');
        });
        $schema->create('tx_auth_snapshot', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('source_id');
            $t->integer('user_id'); $t->unsignedBigInteger('access_subject_id');
            $t->unsignedBigInteger('subscription_id'); $t->unsignedBigInteger('terms_id');
            $t->unsignedBigInteger('usage_period_id'); $t->unsignedBigInteger('tariff_revision_id');
            $t->unsignedBigInteger('auth_version'); $t->unsignedBigInteger('revocation_version');
            $t->unsignedBigInteger('issued_at'); $t->unsignedBigInteger('lease_expires_at');
            $t->unsignedBigInteger('revoked_at')->nullable();
            $t->char('content_hash', 64); $t->unsignedBigInteger('created_at');
            $t->foreign('source_id')->references('id')->on('tx_node_usage_source')->restrictOnDelete();
            $t->foreign(['subscription_id', 'user_id'])->references(['id', 'user_id'])->on('tx_subscription')->restrictOnDelete();
            $t->foreign(['access_subject_id', 'subscription_id'])->references(['id', 'subscription_id'])->on('tx_access_subject')->restrictOnDelete();
            $t->foreign(['terms_id', 'subscription_id'])->references(['id', 'subscription_id'])->on('tx_subscription_terms')->restrictOnDelete();
            $t->foreign(['usage_period_id', 'subscription_id'])->references(['id', 'subscription_id'])->on('tx_usage_period')->restrictOnDelete();
            $t->foreign('tariff_revision_id')->references('id')->on('tx_tariff_revision')->restrictOnDelete();
            $t->index(['source_id', 'user_id', 'lease_expires_at'], 'tx_auth_source_user_idx');
        });
        $schema->create('tx_usage_batch', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('source_id'); $t->unsignedBigInteger('node_id');
            $t->unsignedBigInteger('source_epoch'); $t->string('batch_id', 80);
            $t->string('core_instance_id', 80); $t->unsignedBigInteger('receipt_generation');
            $t->string('receipt_id', 80)->unique(); $t->char('payload_digest', 71);
            $t->longText('canonical_payload'); $t->json('frozen_entries');
            $t->char('frozen_hash', 64); $t->unsignedBigInteger('started_at'); $t->unsignedBigInteger('ended_at');
            $t->enum('status', ['pending', 'processing', 'retry_wait', 'quarantined', 'settled'])->default('pending');
            $t->unsignedBigInteger('accepted_at'); $t->unsignedBigInteger('updated_at');
            $t->unsignedBigInteger('settled_at')->nullable(); $t->unsignedBigInteger('processing_lease_until')->nullable();
            $t->unsignedBigInteger('fencing_token')->default(0); $t->unsignedBigInteger('attempts')->default(0);
            $t->string('reason_code', 80)->nullable();
            $t->unique(['node_id', 'source_epoch', 'batch_id'], 'tx_usage_batch_identity_uq');
            $t->index(['source_id', 'started_at', 'ended_at'], 'tx_usage_batch_interval_idx');
            $t->foreign('source_id')->references('id')->on('tx_node_usage_source')->restrictOnDelete();
        });
        $schema->create('tx_usage_entry', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('batch_id'); $t->unsignedInteger('entry_no');
            $t->unsignedBigInteger('subscription_id'); $t->unsignedBigInteger('usage_period_id');
            $t->unsignedBigInteger('access_subject_id'); $t->unsignedBigInteger('auth_snapshot_id');
            $t->unsignedBigInteger('tariff_revision_id'); $t->unsignedBigInteger('terms_id');
            foreach (['raw_up_bytes','raw_down_bytes','billed_up_bytes','billed_down_bytes','numerator','denominator'] as $column) { $t->unsignedBigInteger($column); }
            $t->string('rounding_revision', 40); $t->unsignedBigInteger('created_at');
            $t->unique(['batch_id', 'entry_no'], 'tx_usage_entry_batch_uq');
            $t->foreign('batch_id')->references('id')->on('tx_usage_batch')->restrictOnDelete();
            $t->foreign(['usage_period_id', 'subscription_id'])->references(['id', 'subscription_id'])->on('tx_usage_period')->restrictOnDelete();
            $t->foreign(['access_subject_id', 'subscription_id'])->references(['id', 'subscription_id'])->on('tx_access_subject')->restrictOnDelete();
            $t->foreign(['terms_id', 'subscription_id'])->references(['id', 'subscription_id'])->on('tx_subscription_terms')->restrictOnDelete();
            $t->foreign('auth_snapshot_id')->references('id')->on('tx_auth_snapshot')->restrictOnDelete();
            $t->foreign('tariff_revision_id')->references('id')->on('tx_tariff_revision')->restrictOnDelete();
        });
        $schema->create('tx_usage_outbox', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('batch_id');
            $t->string('event_key', 128)->unique(); $t->string('kind', 32);
            $t->enum('status', ['pending','completed'])->default('pending');
            $t->json('body'); $t->unsignedBigInteger('created_at'); $t->unsignedBigInteger('completed_at')->nullable();
            $t->foreign('batch_id')->references('id')->on('tx_usage_batch')->restrictOnDelete();
            $t->index(['kind', 'status', 'id'], 'tx_usage_outbox_scan_idx');
        });
        $schema->create('tx_node_usage_stat', function (Blueprint $t) {
            $t->unsignedBigInteger('node_id')->primary();
            $t->unsignedBigInteger('raw_up_bytes')->default(0); $t->unsignedBigInteger('raw_down_bytes')->default(0);
            $t->unsignedBigInteger('updated_at');
        });
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Node usage history exists; restore verified backup or fix forward.');
            }
        }
        foreach (array_reverse(self::TABLES) as $table) { Schema::dropIfExists($table); }
    }
};
