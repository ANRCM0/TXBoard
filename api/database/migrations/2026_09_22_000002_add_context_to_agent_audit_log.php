<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tx_agent_audit_log', function (Blueprint $table) {
            $table->string('actor_type', 32)->default('agent')->after('token_id');
            $table->string('protocol', 32)->default('http')->after('client_name');
            $table->boolean('approval_required')->default(false)->after('risk_level');
            $table->unsignedBigInteger('approval_actor')->nullable()->after('approval_required');
            $table->unsignedInteger('started_at')->nullable()->after('approval_actor');
            $table->unsignedInteger('finished_at')->nullable()->after('started_at');

            $table->index(['protocol', 'created_at']);
            $table->index(['approval_required', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tx_agent_audit_log', function (Blueprint $table) {
            $table->dropIndex(['protocol', 'created_at']);
            $table->dropIndex(['approval_required', 'created_at']);
            $table->dropColumn([
                'actor_type',
                'protocol',
                'approval_required',
                'approval_actor',
                'started_at',
                'finished_at',
            ]);
        });
    }
};
