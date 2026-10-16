<?php

use App\Domains\Installation\SchemaReplayValidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLE = 'tx_node_authorization_state';

    public function up(): void
    {
        (new SchemaReplayValidator())->create(self::TABLE, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('node_id')->unique('tx_node_auth_node_uq');
            $table->unsignedBigInteger('machine_id')->nullable();
            $table->string('key_id', 64);
            $table->unsignedBigInteger('key_epoch');
            $table->unsignedBigInteger('revision');
            $table->unsignedBigInteger('issued_at');
            $table->char('snapshot_digest', 64);
            $table->unsignedBigInteger('updated_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Node authorization revisions exist; fix forward or restore verified backup.');
        }
        Schema::dropIfExists(self::TABLE);
    }
};
