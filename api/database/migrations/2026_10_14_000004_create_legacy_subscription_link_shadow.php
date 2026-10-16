<?php
use App\Domains\Installation\SchemaReplayValidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    private const TABLES=['tx_legacy_subscription_link_shadow','tx_legacy_link_checkpoint','tx_legacy_link_exception'];
    public function up(): void
    {
        $schema=new SchemaReplayValidator();
        $schema->create(self::TABLES[0],function(Blueprint $t):void{
            $t->bigIncrements('id');$t->integer('legacy_user_id');$t->unsignedBigInteger('subscription_id');$t->unsignedBigInteger('map_id');
            $t->string('key_id',64);$t->unsignedInteger('key_generation');$t->char('token_digest',64);
            $t->json('context');$t->char('context_hash',64);$t->char('source_hash',64);$t->char('content_hash',64);$t->unsignedBigInteger('captured_at');
            $t->unique('legacy_user_id','tx_legacy_link_user_uq');$t->unique('subscription_id','tx_legacy_link_sub_uq');$t->unique('map_id','tx_legacy_link_map_uq');
            $t->unique(['key_id','key_generation','token_digest'],'tx_legacy_link_digest_uq');
        });
        $schema->create(self::TABLES[1],function(Blueprint $t):void{
            $t->bigIncrements('id');$t->string('run_key',64);$t->string('mapping_run',64);$t->integer('high_water_user_id');
            $t->integer('cursor_user_id');$t->unsignedBigInteger('processed_count');$t->string('phase',32);$t->json('context');$t->char('context_hash',64);
            $t->unique('run_key','tx_legacy_link_run_uq');
        });
        $schema->create(self::TABLES[2],function(Blueprint $t):void{
            $t->bigIncrements('id');$t->string('run_key',64);$t->integer('legacy_user_id');$t->string('error_code',64);$t->string('state',32);$t->unsignedInteger('attempts');
            $t->unique(['run_key','legacy_user_id'],'tx_legacy_link_exception_uq');
        });
    }
    public function down(): void
    {
        foreach(self::TABLES as$table)if(Schema::hasTable($table)&&DB::table($table)->exists())throw new RuntimeException('LINK_POPULATED_ROLLBACK_REFUSED');
        foreach(array_reverse(self::TABLES)as$table)Schema::dropIfExists($table);
    }
};
