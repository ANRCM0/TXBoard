<?php

use App\Services\Subscription\ShadowSchemaV1;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        (new ShadowSchemaV1())->create('tx_legacy_user_deletion_shadow', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->integer('legacy_user_id');
            $t->string('source_kind', 32);
            $t->string('source_version', 32);
            $t->integer('actor_id');
            $t->json('context');
            $t->char('context_hash', 64);
            $t->json('admitted_snapshot');
            $t->char('admitted_hash', 64);
            $t->boolean('deletion_confirmed');
            $t->unsignedBigInteger('captured_at');
            $t->char('content_hash', 64);
            $t->unique('legacy_user_id', 'tx_deletion_shadow_user_uq');
        });
    }
    public function down(): void
    {
        if (Schema::hasTable('tx_legacy_user_deletion_shadow') && DB::table('tx_legacy_user_deletion_shadow')->exists()) {
            throw new RuntimeException('DELETION_SHADOW_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::dropIfExists('tx_legacy_user_deletion_shadow');
    }
};
