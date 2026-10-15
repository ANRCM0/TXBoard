<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_credential_provider_registration', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('installation_id', 36);
            $table->string('provider_id', 64)->unique();
            $table->unsignedBigInteger('provider_instance_epoch');
            $table->char('config_digest', 64);
            $table->string('business_key', 128)->unique();
            $table->json('profiles');
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('created_at');
        });
        Schema::table('tx_access_subject', function (Blueprint $table): void {
            $table->string('authority_version', 64)->nullable();
            $table->char('installation_id', 36)->nullable();
            $table->unsignedBigInteger('provider_registration_id')->nullable();
            $table->string('credential_profile_id', 64)->nullable();
            $table->unsignedBigInteger('bound_subscription_version')->nullable();
            $table->unsignedBigInteger('bound_terms_id')->nullable();
            $table->string('credential_ref', 64)->nullable();
            $table->unsignedBigInteger('credential_generation')->nullable();
            $table->unsignedBigInteger('issued_at')->nullable();
            $table->unsignedBigInteger('not_before_at')->nullable();
            $table->unsignedBigInteger('credential_expires_at')->nullable();
            $table->unsignedBigInteger('provider_revoked_at')->nullable();
            $table->foreign('provider_registration_id', 'tx_native_subject_registration_fk')
                ->references('id')->on('tx_credential_provider_registration')->restrictOnDelete();
            $table->foreign(['bound_terms_id', 'subscription_id'], 'tx_native_subject_terms_fk')
                ->references(['id', 'subscription_id'])->on('tx_subscription_terms')->restrictOnDelete();
            $table->index(['subscription_id', 'id'], 'tx_native_subject_sub_idx');
        });
        Schema::create('tx_credential_provider_operation', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('operation_ref', 35)->unique();
            $table->integer('user_id');
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('access_subject_id');
            $table->unsignedBigInteger('provider_registration_id');
            $table->string('business_key', 128);
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->char('request_hash', 64);
            $table->json('request_snapshot');
            $table->json('wire_request');
            $table->char('wire_hash', 64);
            $table->json('result_snapshot')->nullable();
            $table->char('result_hash', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('remediation_operation_ref', 35)->nullable();
            $table->unsignedBigInteger('attempts')->default(0);
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
            $table->unique(['user_id', 'business_key'], 'tx_native_cp_operation_business_uq');
            $table->foreign(['subscription_id', 'user_id'], 'tx_native_cp_operation_owner_fk')
                ->references(['id', 'user_id'])->on('tx_subscription')->restrictOnDelete();
            $table->foreign(['access_subject_id', 'subscription_id'], 'tx_native_cp_operation_subject_fk')
                ->references(['id', 'subscription_id'])->on('tx_access_subject')->restrictOnDelete();
            $table->foreign('provider_registration_id', 'tx_native_cp_operation_registration_fk')
                ->references('id')->on('tx_credential_provider_registration')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_credential_provider_registration')->exists()
            || DB::table('tx_credential_provider_operation')->exists()
            || DB::table('tx_access_subject')->whereNotNull('authority_version')->orWhereNotNull('installation_id')
                ->orWhereNotNull('provider_registration_id')->orWhereNotNull('credential_profile_id')
                ->orWhereNotNull('bound_subscription_version')->orWhereNotNull('bound_terms_id')
                ->orWhereNotNull('credential_ref')->orWhereNotNull('credential_generation')
                ->orWhereNotNull('issued_at')->orWhereNotNull('not_before_at')
                ->orWhereNotNull('credential_expires_at')->orWhereNotNull('provider_revoked_at')->exists()) {
            throw new RuntimeException('NATIVE_CREDENTIAL_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::drop('tx_credential_provider_operation');
        // InnoDB may replace the original FK index with our covering index during up().
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && !Schema::hasIndex('tx_access_subject', ['subscription_id'])) {
            Schema::table('tx_access_subject', function (Blueprint $table): void {
                $table->index('subscription_id', 'tx_subject_sub_fk');
            });
        }
        Schema::table('tx_access_subject', function (Blueprint $table): void {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['provider_registration_id'] : 'tx_native_subject_registration_fk');
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['bound_terms_id', 'subscription_id'] : 'tx_native_subject_terms_fk');
            $table->dropIndex('tx_native_subject_sub_idx');
            $table->dropColumn(['authority_version', 'installation_id', 'provider_registration_id', 'credential_profile_id',
                'bound_subscription_version', 'bound_terms_id', 'credential_ref', 'credential_generation', 'issued_at',
                'not_before_at', 'credential_expires_at', 'provider_revoked_at']);
            if (DB::getDriverName() === 'sqlite') {
                $table->enum('status', ['pending', 'active', 'revoked'])->change();
            }
        });
        Schema::drop('tx_credential_provider_registration');
    }
};
