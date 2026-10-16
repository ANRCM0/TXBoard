<?php

use App\Domains\Installation\SchemaReplayValidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = new SchemaReplayValidator();
        $schema->create('tx_credential_provider_shadow_registration', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('provider_id', 64)->unique('tx_cp_shadow_provider_uq');
            $t->unsignedBigInteger('instance_epoch');
            $t->string('runtime_code', 64);
            $t->char('script_digest', 64);
            $t->json('operation_grants');
            $t->json('profile_grants');
            $t->boolean('enabled');
            $t->unsignedBigInteger('created_at');
            $t->unique(['id', 'instance_epoch'], 'tx_cp_shadow_epoch_uq');
        });
        $schema->create('tx_credential_provider_shadow_operation', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('registration_id');
            $t->unsignedBigInteger('instance_epoch');
            $t->unsignedBigInteger('access_subject_id');
            $t->string('operation', 16);
            $t->string('operation_ref', 64)->unique('tx_cp_shadow_operation_ref_uq');
            $t->string('idempotency_key', 128);
            $t->char('input_hash', 64);
            $t->json('input_snapshot');
            $t->enum('status', ['recorded', 'unknown', 'succeeded', 'rejected']);
            $t->json('result_snapshot')->nullable();
            $t->char('result_hash', 64)->nullable();
            $t->string('last_error_code', 64)->nullable();
            $t->unsignedBigInteger('created_at');
            $t->unsignedBigInteger('updated_at');
            $t->unique(['registration_id', 'operation', 'idempotency_key'], 'tx_cp_shadow_business_uq');
            $t->unique(['registration_id', 'access_subject_id', 'operation'], 'tx_cp_shadow_subject_uq');
            $t->foreign(['registration_id', 'instance_epoch'], 'tx_cp_shadow_registration_fk')
                ->references(['id', 'instance_epoch'])->on('tx_credential_provider_shadow_registration')->restrictOnDelete();
            $t->foreign('access_subject_id', 'tx_cp_shadow_subject_fk')->references('id')->on('tx_access_subject')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['tx_credential_provider_shadow_operation', 'tx_credential_provider_shadow_registration'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Provider shadow history exists; fix forward or restore a verified backup.');
            }
        }
        Schema::dropIfExists('tx_credential_provider_shadow_operation');
        Schema::dropIfExists('tx_credential_provider_shadow_registration');
    }
};
