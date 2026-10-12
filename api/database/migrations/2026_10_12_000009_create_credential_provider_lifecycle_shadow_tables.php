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
        $schema->create('tx_credential_provider_shadow_lifecycle', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('registration_id')->unique('tx_cp_lifecycle_registration_uq');
            $t->unsignedBigInteger('instance_epoch');
            $t->enum('state', ['enabled', 'disabling', 'disabled']);
            $t->unsignedBigInteger('version_no');
            $t->unsignedBigInteger('last_fact_version')->default(0);
            $t->string('disable_operation_ref', 64)->nullable()->unique('tx_cp_lifecycle_operation_uq');
            $t->string('disable_key', 128)->nullable();
            $t->json('disable_input')->nullable();
            $t->char('disable_hash', 64)->nullable();
            $t->unsignedBigInteger('high_water_id')->default(0);
            $t->unsignedBigInteger('cursor_id')->default(0);
            $t->string('last_error_code', 64)->nullable();
            $t->unsignedBigInteger('started_at')->nullable();
            $t->unsignedBigInteger('disabled_at')->nullable();
            $t->unsignedBigInteger('created_at');
            $t->unsignedBigInteger('updated_at');
            $t->foreign(['registration_id', 'instance_epoch'], 'tx_cp_lifecycle_registration_fk')
                ->references(['id', 'instance_epoch'])->on('tx_credential_provider_shadow_registration')->restrictOnDelete();
        });
        $schema->create('tx_credential_provider_shadow_revocation', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('registration_id');
            $t->unsignedBigInteger('instance_epoch');
            $t->unsignedBigInteger('access_subject_id');
            $t->unsignedBigInteger('operation_id')->unique('tx_cp_revocation_operation_uq');
            $t->unsignedBigInteger('fact_version');
            $t->unsignedBigInteger('expected_subject_version');
            $t->string('reason_code', 32);
            $t->unsignedBigInteger('effective_at');
            $t->char('content_hash', 64);
            $t->unsignedBigInteger('created_at');
            $t->unique(['registration_id', 'access_subject_id'], 'tx_cp_revocation_subject_uq');
            $t->unique(['registration_id', 'fact_version'], 'tx_cp_revocation_version_uq');
            $t->foreign(['registration_id', 'instance_epoch'], 'tx_cp_revocation_registration_fk')
                ->references(['id', 'instance_epoch'])->on('tx_credential_provider_shadow_registration')->restrictOnDelete();
            $t->foreign('operation_id', 'tx_cp_revocation_operation_fk')
                ->references('id')->on('tx_credential_provider_shadow_operation')->restrictOnDelete();
            $t->foreign('access_subject_id', 'tx_cp_revocation_subject_fk')
                ->references('id')->on('tx_access_subject')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['tx_credential_provider_shadow_revocation', 'tx_credential_provider_shadow_lifecycle'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Provider lifecycle shadow history exists; fix forward or restore a verified backup.');
            }
        }
        Schema::dropIfExists('tx_credential_provider_shadow_revocation');
        Schema::dropIfExists('tx_credential_provider_shadow_lifecycle');
    }
};
