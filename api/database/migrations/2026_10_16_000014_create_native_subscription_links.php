<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tx_subscription_link', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('installation_id', 36);
            $table->integer('user_id');
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('access_subject_id');
            $table->string('link_ref', 37)->unique();
            $table->char('bearer_hash', 64);
            $table->string('business_key', 128);
            $table->char('request_hash', 64);
            $table->string('status', 16);
            $table->unsignedBigInteger('row_version');
            $table->unsignedBigInteger('expires_at');
            $table->unsignedBigInteger('revoked_at')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->char('content_hash', 64);
            $table->unique(['installation_id', 'user_id', 'business_key'], 'tx_sub_link_business_uq');
            $table->foreign(['subscription_id', 'user_id'], 'tx_sub_link_owner_fk')
                ->references(['id', 'user_id'])->on('tx_subscription')->restrictOnDelete();
            $table->foreign(['access_subject_id', 'subscription_id'], 'tx_sub_link_subject_fk')
                ->references(['id', 'subscription_id'])->on('tx_access_subject')->restrictOnDelete();
            $table->index(['user_id', 'id'], 'tx_sub_link_user_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('tx_subscription_link')->exists()) {
            throw new RuntimeException('NATIVE_SUBSCRIPTION_LINK_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::drop('tx_subscription_link');
    }
};
