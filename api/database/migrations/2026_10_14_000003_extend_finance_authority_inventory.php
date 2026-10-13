<?php

use App\Services\Subscription\ShadowSchemaV1;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        (new ShadowSchemaV1())->create('tx_authority_finance_upgrade',function(Blueprint $t):void{
            $t->string('upgrade_key',64)->primary();$t->string('scope',32);$t->string('version',32);
            $t->string('prior_scope',32);$t->string('prior_version',32);
            $t->unsignedBigInteger('expected_epoch');$t->unsignedBigInteger('expanded_epoch');
            $t->unsignedBigInteger('prior_updated_at');$t->unsignedBigInteger('captured_at');
            $t->char('parent_inventory_upgrade_hash',64);$t->char('inventory_hash',64);$t->char('request_hash',64);
            $t->char('prior_state_hash',64);$t->char('expanded_state_hash',64);$t->char('content_hash',64);
        });
    }
    public function down():void
    {
        if(Schema::hasTable('tx_authority_finance_upgrade')&&DB::table('tx_authority_finance_upgrade')->exists())
            throw new RuntimeException('AUTHORITY_FINANCE_POPULATED_ROLLBACK_REFUSED');
        Schema::dropIfExists('tx_authority_finance_upgrade');
    }
};
