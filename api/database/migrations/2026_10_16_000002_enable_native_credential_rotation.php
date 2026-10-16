<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tx_credential_provider_operation', function (Blueprint $table): void {
            $table->unique(['operation_ref', 'access_subject_id', 'subscription_id', 'provider_registration_id'],
                'tx_native_cp_current_owner_uq');
        });
        Schema::table('tx_access_subject', function (Blueprint $table): void {
            $table->string('current_credential_operation_ref', 35)->nullable();
            $table->foreign(['current_credential_operation_ref', 'id', 'subscription_id', 'provider_registration_id'],
                'tx_native_subject_current_operation_fk')
                ->references(['operation_ref', 'access_subject_id', 'subscription_id', 'provider_registration_id'])
                ->on('tx_credential_provider_operation')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_access_subject')->whereNotNull('current_credential_operation_ref')->exists()
            || DB::table('tx_credential_provider_operation')->where('kind', 'rotate')->exists()) {
            throw new RuntimeException('NATIVE_CREDENTIAL_ROTATION_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::table('tx_access_subject', function (Blueprint $table): void {
            $table->dropForeign(DB::getDriverName() === 'sqlite'
                ? ['current_credential_operation_ref', 'id', 'subscription_id', 'provider_registration_id']
                : 'tx_native_subject_current_operation_fk');
            $table->dropColumn('current_credential_operation_ref');
        });
        Schema::table('tx_credential_provider_operation', function (Blueprint $table): void {
            $table->dropUnique('tx_native_cp_current_owner_uq');
        });
    }
};
