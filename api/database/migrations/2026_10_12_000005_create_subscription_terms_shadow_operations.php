<?php

use App\Domains\Installation\SchemaReplayValidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        (new SchemaReplayValidator())->create('tx_subscription_terms_shadow_operation', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id');
            $table->unsignedBigInteger('subscription_id');
            $table->string('business_key', 128);
            $table->enum('kind', ['renew', 'limits', 'quota', 'price_evidence', 'admin', 'reset']);
            $table->string('policy_version', 64);
            $table->char('request_hash', 64);
            $table->json('request_snapshot');
            $table->json('result_snapshot');
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('terms_id');
            $table->unsignedBigInteger('transition_id')->nullable();
            $table->unsignedBigInteger('previous_period_id');
            $table->unsignedBigInteger('next_period_id');
            $table->unsignedBigInteger('created_at');
            $table->unique(['subscription_id', 'business_key'], 'tx_terms_shadow_operation_business_uq');
            $table->unique('transition_id', 'tx_terms_shadow_operation_transition_uq');
            $table->foreign(['subscription_id', 'user_id'], 'tx_terms_shadow_operation_owner_fk')->references(['id', 'user_id'])->on('tx_subscription')->restrictOnDelete();
            $table->foreign(['terms_id', 'subscription_id'], 'tx_terms_shadow_operation_terms_fk')->references(['id', 'subscription_id'])->on('tx_subscription_terms')->restrictOnDelete();
            $table->foreign('transition_id', 'tx_terms_shadow_operation_transition_fk')->references('id')->on('tx_subscription_terms_transition')->restrictOnDelete();
            $table->foreign(['previous_period_id', 'subscription_id'], 'tx_terms_shadow_operation_previous_fk')->references(['id', 'subscription_id'])->on('tx_usage_period')->restrictOnDelete();
            $table->foreign(['next_period_id', 'subscription_id'], 'tx_terms_shadow_operation_next_fk')->references(['id', 'subscription_id'])->on('tx_usage_period')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tx_subscription_terms_shadow_operation') && DB::table('tx_subscription_terms_shadow_operation')->exists()) {
            throw new RuntimeException('Terms shadow operation history exists; restore a verified backup or fix forward.');
        }
        Schema::dropIfExists('tx_subscription_terms_shadow_operation');
    }
};
