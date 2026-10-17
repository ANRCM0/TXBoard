<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_native_runtime_trust_transition', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('installation_id', 36);
            $table->unsignedBigInteger('version');
            $table->string('business_key', 128);
            $table->char('request_hash', 64);
            $table->char('previous_digest', 64);
            $table->char('configuration_digest', 64);
            $table->json('configuration_snapshot');
            $table->json('provider_capabilities');
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('created_at');
            $table->unique(['installation_id', 'version'], 'tx_native_trust_version_uq');
            $table->unique(['installation_id', 'business_key'], 'tx_native_trust_key_uq');
            $table->unique(['installation_id', 'configuration_digest'], 'tx_native_trust_digest_uq');
            $table->foreign('installation_id', 'tx_native_trust_installation_fk')
                ->references('installation_id')->on('tx_native_runtime_activation')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_native_runtime_trust_transition')->exists()) {
            throw new RuntimeException('NATIVE_TRUST_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::drop('tx_native_runtime_trust_transition');
    }
};
