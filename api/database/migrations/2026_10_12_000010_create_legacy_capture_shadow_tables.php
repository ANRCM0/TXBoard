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
        $schema->create('tx_legacy_capture_shadow_stream', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->integer('legacy_user_id');
            $t->json('context');
            $t->char('context_hash', 64);
            $t->unsignedBigInteger('last_sequence')->default(0);
            $t->char('last_event_hash', 64)->nullable();
            $t->char('last_after_hash', 64)->nullable();
            $t->unsignedBigInteger('created_at');
            $t->unsignedBigInteger('updated_at');
            $t->unique('legacy_user_id', 'tx_capture_user_uq');
        });
        $schema->create('tx_legacy_capture_shadow_event', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('stream_id');
            $t->integer('legacy_user_id');
            $t->unsignedBigInteger('sequence');
            $t->string('source_kind', 32);
            $t->string('source_version', 32);
            $t->integer('actor_id');
            $t->json('before_snapshot');
            $t->json('after_snapshot');
            $t->char('before_hash', 64);
            $t->char('after_hash', 64);
            $t->char('previous_event_hash', 64)->nullable();
            $t->char('context_hash', 64);
            $t->unsignedBigInteger('captured_at');
            $t->char('content_hash', 64);
            $t->unique(['stream_id', 'sequence'], 'tx_capture_sequence_uq');
            $t->foreign('stream_id', 'tx_capture_stream_fk')->references('id')
                ->on('tx_legacy_capture_shadow_stream')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['tx_legacy_capture_shadow_event', 'tx_legacy_capture_shadow_stream'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('CAPTURE_POPULATED_ROLLBACK_REFUSED');
            }
        }
        Schema::dropIfExists('tx_legacy_capture_shadow_event');
        Schema::dropIfExists('tx_legacy_capture_shadow_stream');
    }
};
