<?php

use App\Domains\Installation\SchemaReplayValidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        (new SchemaReplayValidator())->create('tx_authority_ticket_upgrade',function(Blueprint $t):void{
            $t->string('upgrade_key',64)->primary();$t->string('scope',32);$t->string('version',32);
            $t->string('prior_scope',32);$t->string('prior_version',32);$t->unsignedBigInteger('expected_epoch');$t->unsignedBigInteger('expanded_epoch');
            $t->unsignedBigInteger('prior_updated_at');$t->unsignedBigInteger('captured_at');
            foreach(['parent_identity_upgrade_hash','inventory_hash','request_hash','prior_state_hash','expanded_state_hash','content_hash']as$key)$t->char($key,64);
        });
    }
    public function down():void
    {
        if(Schema::hasTable('tx_authority_ticket_upgrade')&&DB::table('tx_authority_ticket_upgrade')->exists())throw new RuntimeException('AUTHORITY_TICKET_POPULATED_ROLLBACK_REFUSED');
        Schema::dropIfExists('tx_authority_ticket_upgrade');
    }
};
