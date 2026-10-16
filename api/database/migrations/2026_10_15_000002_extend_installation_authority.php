<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tx_authority_writer_fence', function (Blueprint $table): void {
            $table->enum('authority', ['legacy', 'native'])->change();
            $table->enum('phase', ['open', 'prepared', 'initializing'])->change();
        });
        Schema::table('tx_authority_writer_fence', function (Blueprint $table): void {
            $table->string('installation_id', 36)->nullable()->unique();
            $table->string('bootstrap_key', 64)->nullable()->unique();
            $table->char('bootstrap_request_hash', 64)->nullable();
            $table->unsignedInteger('schema_version')->nullable();
            $table->unsignedInteger('domain_version')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('tx_authority_writer_fence')->where(function ($query): void {
            $query->where('authority', 'native')->orWhere('phase', 'initializing')
                ->orWhereNotNull('installation_id')->orWhereNotNull('bootstrap_key')
                ->orWhereNotNull('bootstrap_request_hash')->orWhereNotNull('schema_version')->orWhereNotNull('domain_version');
        })->exists()) {
            throw new RuntimeException('INSTALLATION_AUTHORITY_POPULATED_ROLLBACK_REFUSED');
        }
        Schema::table('tx_authority_writer_fence', function (Blueprint $table): void {
            $table->dropUnique(['installation_id']);
            $table->dropUnique(['bootstrap_key']);
            $table->dropColumn(['installation_id', 'bootstrap_key', 'bootstrap_request_hash', 'schema_version', 'domain_version']);
        });
        Schema::table('tx_authority_writer_fence', function (Blueprint $table): void {
            $table->enum('authority', ['legacy'])->change();
            $table->enum('phase', ['open', 'prepared'])->change();
        });
    }
};
