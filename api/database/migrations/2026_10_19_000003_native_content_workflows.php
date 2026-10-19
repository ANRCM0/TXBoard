<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Each DDL step can resume after an interrupted MySQL migration.
        foreach (['tx_notice', 'tx_knowledge'] as $name) {
            if (!Schema::hasColumn($name, 'row_version')) Schema::table($name, fn (Blueprint $t) => $t->unsignedBigInteger('row_version')->default(1));
            if (!Schema::hasColumn($name, 'audience')) Schema::table($name, fn (Blueprint $t) => $t->string('audience', 24)->default('authenticated'));
            foreach (['publish_at', 'expire_at', 'deleted_at'] as $column) {
                if (!Schema::hasColumn($name, $column)) Schema::table($name, fn (Blueprint $t) => $t->unsignedBigInteger($column)->nullable());
            }
        }
        if (!Schema::hasTable('tx_content_collection')) Schema::create('tx_content_collection', function (Blueprint $t): void {
            $t->string('kind', 16)->primary();
        });
        if (!Schema::hasTable('tx_content_request')) Schema::create('tx_content_request', function (Blueprint $t): void {
            $t->char('key_hash', 64)->primary(); $t->uuid('installation_id'); $t->integer('actor_id');
            $t->string('operation', 48); $t->char('input_hash', 64); $t->mediumText('response_json');
            $t->char('response_hash', 64); $t->unsignedBigInteger('created_at');
            $t->foreign('actor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
        if (!Schema::hasTable('tx_content_audit')) Schema::create('tx_content_audit', function (Blueprint $t): void {
            $t->bigIncrements('id'); $t->uuid('installation_id'); $t->integer('actor_id');
            $t->string('kind', 16); $t->string('operation', 24); $t->mediumText('changes_json');
            $t->char('input_hash', 64); $t->string('correlation_id', 128);
            $t->unsignedBigInteger('occurred_at'); $t->char('content_hash', 64);
            $t->foreign('actor_id')->references('id')->on('tx_user')->restrictOnDelete();
            $t->foreign('installation_id')->references('installation_id')->on('tx_authority_writer_fence')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Check every owner table before the first DDL; MySQL cannot undo a partial rollback.
        foreach (['tx_notice', 'tx_knowledge', 'tx_content_request', 'tx_content_audit'] as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) {
                throw new RuntimeException('NATIVE_CONTENT_FORWARD_RECOVERY_REQUIRED');
            }
        }
        if (Schema::hasTable('tx_content_collection')
            && DB::table('tx_content_collection')->whereNotIn('kind', ['notices', 'knowledge'])->exists()) {
            throw new RuntimeException('NATIVE_CONTENT_FORWARD_RECOVERY_REQUIRED');
        }
        foreach (['tx_content_audit', 'tx_content_request', 'tx_content_collection'] as $name) Schema::dropIfExists($name);
        foreach (['tx_notice', 'tx_knowledge'] as $name) {
            foreach (['row_version', 'audience', 'publish_at', 'expire_at', 'deleted_at'] as $column) {
                if (Schema::hasColumn($name, $column)) Schema::table($name, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
