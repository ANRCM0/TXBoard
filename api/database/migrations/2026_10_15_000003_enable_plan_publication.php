<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tx_plan_revision', function (Blueprint $table): void {
            $table->string('publication_key', 64)->nullable();
            $table->char('publication_request_hash', 64)->nullable();
            $table->unique(['plan_id', 'publication_key'], 'tx_revision_publication_uq');
        });
        Schema::table('tx_plan_offer_availability', function (Blueprint $table): void {
            $table->enum('status', ['shadow', 'superseded', 'published'])->change();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_plan_revision')->whereNotNull('publication_key')->exists()
            || DB::table('tx_plan_revision')->where('schema_version', 'plan-publication/v1')->exists()
            || DB::table('tx_plan_offer_availability')->where('status', 'published')->exists()) {
            throw new RuntimeException('PLAN_PUBLICATION_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::table('tx_plan_offer_availability', function (Blueprint $table): void {
            $table->enum('status', ['shadow', 'superseded'])->change();
        });
        Schema::table('tx_plan_revision', function (Blueprint $table): void {
            $table->dropUnique('tx_revision_publication_uq');
            $table->dropColumn(['publication_key', 'publication_request_hash']);
        });
    }
};
