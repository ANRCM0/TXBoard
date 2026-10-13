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
        $schema->create('tx_authority_writer_fence', function (Blueprint $t): void {
            $t->unsignedTinyInteger('id')->primary();
            $t->string('scope', 32); $t->string('version', 32);
            $t->enum('authority', ['legacy']); $t->enum('phase', ['open','prepared']);
            $t->unsignedBigInteger('authority_epoch'); $t->string('prepare_key', 64)->nullable();
            $t->unsignedBigInteger('row_version'); $t->unsignedBigInteger('updated_at');
            $t->char('content_hash', 64);
        });
        $schema->create('tx_authority_prepare_receipt', function (Blueprint $t): void {
            $t->string('prepare_key', 64)->primary(); $t->string('scope', 32); $t->string('version', 32);
            $t->unsignedBigInteger('expected_epoch'); $t->unsignedBigInteger('prepared_epoch');
            $t->char('request_hash', 64); $t->char('prior_state_hash', 64); $t->char('prepared_state_hash', 64);
            $t->unsignedBigInteger('captured_at'); $t->char('content_hash', 64);
        });
    }
    public function down(): void
    {
        foreach (['tx_authority_writer_fence','tx_authority_prepare_receipt'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) throw new RuntimeException('AUTHORITY_FENCE_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::dropIfExists('tx_authority_prepare_receipt'); Schema::dropIfExists('tx_authority_writer_fence');
    }
};
