<?php

use App\Services\Subscription\ShadowSchemaV1;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public const TABLE = 'tx_credential_provider_shadow_lease_candidate';
    public function up(): void
    {
        (new ShadowSchemaV1())->create(self::TABLE, function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('candidate_ref', 64)->unique('tx_cp_lease_ref_uq');
            $t->string('provider_id', 64); $t->unsignedBigInteger('access_subject_id');
            $t->string('business_key', 128); $t->char('request_hash', 64); $t->json('request_snapshot');
            $t->unsignedBigInteger('binding_id'); $t->unsignedBigInteger('registration_id');
            $t->unsignedBigInteger('issue_operation_id'); $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('subscription_id'); $t->unsignedBigInteger('terms_id');
            $t->unsignedBigInteger('usage_period_id'); $t->unsignedBigInteger('tariff_revision_id');
            $t->json('evidence_snapshot'); $t->char('evidence_hash', 64);
            $t->unsignedBigInteger('prepared_at'); $t->unsignedBigInteger('candidate_expires_at');
            $t->enum('state', ['prepared', 'expired', 'refused']);
            $t->string('reason_code', 80)->nullable(); $t->unsignedBigInteger('last_observed_at');
            $t->unsignedBigInteger('row_version')->default(1);
            $t->unique(['provider_id', 'access_subject_id', 'business_key'], 'tx_cp_lease_key_uq');
            foreach (['binding_id' => 'tx_credential_provider_shadow_plugin_binding',
                'registration_id' => 'tx_credential_provider_shadow_registration',
                'issue_operation_id' => 'tx_credential_provider_shadow_operation',
                'source_id' => 'tx_node_usage_source', 'access_subject_id' => 'tx_access_subject',
                'subscription_id' => 'tx_subscription', 'terms_id' => 'tx_subscription_terms',
                'usage_period_id' => 'tx_usage_period', 'tariff_revision_id' => 'tx_tariff_revision'] as $column => $table) {
                $t->foreign($column, 'tx_cp_lease_'.$column.'_fk')->references('id')->on($table)->restrictOnDelete();
            }
        });
    }
    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Lease candidate history exists; fix forward or restore verified backup.');
        }
        Schema::dropIfExists(self::TABLE);
    }
};
