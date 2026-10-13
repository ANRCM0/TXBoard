<?php

use App\Services\Subscription\ShadowSchemaV1;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        if(!Schema::hasColumn('tx_authority_writer_fence','inventory_hash')) {
            Schema::table('tx_authority_writer_fence',fn(Blueprint $t)=>$t->char('inventory_hash',64)->nullable());
        }
        $column=collect(Schema::getColumns('tx_authority_writer_fence'))->firstWhere('name','inventory_hash');
        if(!$column || !$column['nullable'] || !in_array($column['type_name'],DB::getDriverName()==='sqlite'?['varchar']:['char'],true)
            || (DB::getDriverName()!=='sqlite' && $column['type']!=='char(64)'))throw new RuntimeException('AUTHORITY_INVENTORY_SCHEMA_INVALID');
        (new ShadowSchemaV1())->create('tx_authority_inventory_upgrade',function(Blueprint $t):void{
            $t->string('upgrade_key',64)->primary();$t->string('scope',32);$t->string('version',32);
            $t->string('prior_scope',32);$t->string('prior_version',32);
            $t->unsignedBigInteger('expected_epoch');$t->unsignedBigInteger('expanded_epoch');
            $t->unsignedBigInteger('prior_updated_at');$t->unsignedBigInteger('captured_at');
            $t->char('inventory_hash',64);$t->char('request_hash',64);$t->char('prior_state_hash',64);$t->char('expanded_state_hash',64);
            $t->char('content_hash',64);
        });
    }
    public function down():void
    {
        foreach(['tx_authority_writer_fence','tx_authority_prepare_receipt','tx_authority_inventory_upgrade']as$table)
            if(Schema::hasTable($table)&&DB::table($table)->exists())throw new RuntimeException('AUTHORITY_INVENTORY_POPULATED_ROLLBACK_REFUSED');
        Schema::dropIfExists('tx_authority_inventory_upgrade');
        if(Schema::hasColumn('tx_authority_writer_fence','inventory_hash'))Schema::table('tx_authority_writer_fence',fn(Blueprint $t)=>$t->dropColumn('inventory_hash'));
    }
};
