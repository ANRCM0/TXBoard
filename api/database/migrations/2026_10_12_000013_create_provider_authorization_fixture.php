<?php

use App\Domains\Installation\SchemaReplayValidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public const TABLE = 'tx_credential_provider_shadow_authorization';
    public function up(): void
    {
        (new SchemaReplayValidator())->create(self::TABLE, function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('snapshot_ref', 64)->unique('tx_cp_auth_ref_uq');
            $t->string('provider_id', 64); $t->unsignedBigInteger('access_subject_id')->unique('tx_cp_auth_subject_uq');
            $t->unsignedBigInteger('candidate_id')->unique('tx_cp_auth_candidate_uq');
            $t->unsignedBigInteger('issue_operation_id')->unique('tx_cp_auth_issue_uq');
            $t->unsignedBigInteger('binding_id'); $t->unsignedBigInteger('registration_id');
            $t->unsignedBigInteger('source_id'); $t->unsignedBigInteger('subscription_id'); $t->unsignedBigInteger('terms_id');
            $t->unsignedBigInteger('usage_period_id'); $t->unsignedBigInteger('tariff_revision_id');
            $t->string('business_key', 128); $t->char('request_hash', 64); $t->json('request_snapshot');
            $t->json('payload_snapshot'); $t->char('payload_hash', 64); $t->longText('signed_snapshot'); $t->char('signature_hash', 64);
            $t->string('key_id', 64); $t->unsignedBigInteger('key_epoch'); $t->char('key_spki_sha256', 64);
            $t->unsignedBigInteger('issued_at'); $t->unsignedBigInteger('lease_expires_at');
            $t->enum('state', ['valid', 'expired', 'refused']); $t->string('reason_code', 80)->nullable();
            $t->unsignedBigInteger('last_observed_at'); $t->unsignedBigInteger('row_version')->default(1);
            $t->unsignedBigInteger('created_at');
            $t->unique(['provider_id', 'business_key'], 'tx_cp_auth_key_uq');
            foreach (['candidate_id'=>'tx_credential_provider_shadow_lease_candidate', 'access_subject_id'=>'tx_access_subject',
                'issue_operation_id'=>'tx_credential_provider_shadow_operation', 'binding_id'=>'tx_credential_provider_shadow_plugin_binding',
                'registration_id'=>'tx_credential_provider_shadow_registration', 'source_id'=>'tx_node_usage_source',
                'subscription_id'=>'tx_subscription', 'terms_id'=>'tx_subscription_terms', 'usage_period_id'=>'tx_usage_period',
                'tariff_revision_id'=>'tx_tariff_revision'] as $column=>$table) {
                $t->foreign($column, 'tx_cp_auth_'.$column.'_fk')->references('id')->on($table)->restrictOnDelete();
            }
        });
    }
    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Authorization fixture history exists; fix forward or restore verified backup.');
        }
        Schema::dropIfExists(self::TABLE);
    }
};
