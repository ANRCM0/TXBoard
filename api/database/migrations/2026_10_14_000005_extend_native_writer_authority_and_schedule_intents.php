<?php

use App\Domains\Installation\SchemaReplayValidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        $schema=new SchemaReplayValidator();
        $schema->create('tx_authority_native_upgrade',function(Blueprint $t):void{
            $t->string('upgrade_key',64)->primary();$t->string('scope',32);$t->string('version',32);
            $t->string('prior_scope',32);$t->string('prior_version',32);
            $t->unsignedBigInteger('expected_epoch');$t->unsignedBigInteger('expanded_epoch');
            $t->unsignedBigInteger('prior_updated_at');$t->unsignedBigInteger('captured_at');
            $t->char('parent_finance_upgrade_hash',64);$t->char('inventory_hash',64);$t->char('request_hash',64);
            $t->char('prior_state_hash',64);$t->char('expanded_state_hash',64);$t->char('content_hash',64);
        });
        $schema->create('tx_authority_postcommit_intent',function(Blueprint $t):void{
            $t->char('intent_id',32)->primary();$t->string('scope',32);$t->string('version',32);$t->unsignedBigInteger('authority_epoch');
            $t->string('writer',64);$t->unsignedInteger('event_sequence');$t->integer('legacy_user_id');
            $t->enum('action',['created','updated','deleted']);$t->integer('old_group_id')->nullable();
            $t->boolean('schedule_required');$t->char('source_hash',64);$t->unsignedBigInteger('captured_at');$t->char('content_hash',64);
        });
        $schema->create('tx_authority_postcommit_delivery',function(Blueprint $t):void{
            $t->char('intent_id',32)->primary();$t->char('intent_hash',64);
            $t->enum('schedule_state',['pending','applied','not_required']);$t->char('schedule_hash',64)->nullable();
            $t->enum('handoff_state',['pending','processing','handed_off','unknown']);$t->unsignedInteger('attempts')->default(0);
            $t->char('lease_token',32)->nullable();$t->unsignedBigInteger('lease_expires_at')->nullable();
            $t->unsignedBigInteger('updated_at');
            $t->foreign('intent_id','tx_authority_intent_delivery_fk')->references('intent_id')->on('tx_authority_postcommit_intent')->restrictOnDelete();
        });
    }
    public function down():void
    {
        foreach(['tx_authority_native_upgrade','tx_authority_postcommit_intent','tx_authority_postcommit_delivery']as$table)
            if(Schema::hasTable($table)&&DB::table($table)->exists())throw new RuntimeException('AUTHORITY_NATIVE_POPULATED_ROLLBACK_REFUSED');
        Schema::dropIfExists('tx_authority_postcommit_delivery');Schema::dropIfExists('tx_authority_postcommit_intent');Schema::dropIfExists('tx_authority_native_upgrade');
    }
};
