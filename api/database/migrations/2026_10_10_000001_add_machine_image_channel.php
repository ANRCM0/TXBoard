<?php

use App\Support\Database\NativeTableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $tableName = NativeTableName::runtime('v2_server_machine');
        Schema::table($tableName, static function (Blueprint $table): void {
            $table->string('image_channel', 16)->default('stable')->after('is_active');
        });
    }

    public function down(): void
    {
        $tableName = NativeTableName::runtime('v2_server_machine');
        Schema::table($tableName, static function (Blueprint $table): void {
            $table->dropColumn('image_channel');
        });
    }
};
