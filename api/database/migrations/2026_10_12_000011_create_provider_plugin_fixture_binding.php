<?php

use App\Services\Subscription\ShadowSchemaV1;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const TABLE = 'tx_credential_provider_shadow_plugin_binding';

    public function up(): void
    {
        (new ShadowSchemaV1())->create(self::TABLE, function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->enum('plugin_code', ['credential_provider_fixture'])->unique('tx_cp_plugin_code_uq');
            $t->unsignedBigInteger('plugin_id')->unique('tx_cp_plugin_install_uq');
            $t->unsignedBigInteger('registration_id')->unique('tx_cp_plugin_registration_uq');
            $t->unsignedBigInteger('instance_epoch');
            $t->unsignedBigInteger('created_at');
            $t->foreign('plugin_id', 'tx_cp_plugin_install_fk')->references('id')->on('tx_plugins')->restrictOnDelete();
            $t->foreign(['registration_id', 'instance_epoch'], 'tx_cp_plugin_registration_fk')
                ->references(['id', 'instance_epoch'])->on('tx_credential_provider_shadow_registration')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Provider plugin binding history exists; fix forward or restore a verified backup.');
        }
        Schema::dropIfExists(self::TABLE);
    }
};
