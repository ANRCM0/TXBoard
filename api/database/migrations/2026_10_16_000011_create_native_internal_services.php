<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tx_internal_service', function (Blueprint $table): void {
            $table->string('service_id', 64)->primary();
            $table->string('security_domain', 8);
            $table->char('token_hash', 64);
            $table->char('certificate_sha256', 64);
            $table->unsignedBigInteger('epoch');
            $table->text('operations_json');
            $table->unsignedBigInteger('expires_at');
            $table->unsignedBigInteger('revoked_at')->nullable();
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
        });
        Schema::create('tx_internal_actor_grant', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->string('permission', 128);
            $table->unsignedBigInteger('created_at');
            $table->primary(['user_id', 'permission']);
            $table->foreign('user_id', 'tx_internal_actor_grant_user_fk')
                ->references('id')->on('tx_user')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_internal_service')->exists() || DB::table('tx_internal_actor_grant')->exists()) {
            throw new RuntimeException('NATIVE_INTERNAL_SERVICES_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::dropIfExists('tx_internal_actor_grant');
        Schema::dropIfExists('tx_internal_service');
    }
};
