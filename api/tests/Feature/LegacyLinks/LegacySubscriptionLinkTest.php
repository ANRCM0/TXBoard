<?php
namespace Tests\Feature\LegacyLinks;
use App\Domains\LegacyLinks\Canonical;
use App\Domains\LegacyLinks\KeyMaterial;
use App\Domains\LegacyLinks\LinkAssociation;
use App\Domains\LegacyLinks\LinkFailure;
use App\Domains\LegacyLinks\LinkService;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\Subscription\SubscriptionShadowBackfill;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
final class LegacySubscriptionLinkTest extends TestCase
{
    private string $directory;
    private int $sequence=0;
    private Plan $plan;
    private mixed $observed=null;
    private mixed $rendered=null;
    protected function setUp():void
    {
        parent::setUp();$connection=$this->mysql()?'legacy_link_mysql_test':'legacy_link_sqlite_test';
        config(['database.default'=>$connection,'database.connections.'.$connection=>$this->mysql()?config('database.connections.mysql')
            :array_replace(config('database.connections.sqlite'),['database'=>':memory:','foreign_key_constraints'=>true])]);
        DB::purge($connection);
        if($this->mysql()){
            if(DB::connection()->getDatabaseName()!=='txboard_legacy_link_test')throw new \RuntimeException('Exclusive synthetic link database required');
            Schema::dropAllTables();
        }
        $this->assertSame(0,Artisan::call('migrate',['--force'=>true]));
        $this->directory=sys_get_temp_dir().'/txboard-legacy-links-'.bin2hex(random_bytes(8));mkdir($this->directory,0700);
        $this->keyFile();config(['legacy_subscription_links.enabled'=>true,'legacy_subscription_links.key_file'=>$this->directory.'/key.json',
            'core_shadow.isolated_copy'=>true,'cache.stores.redis'=>['driver'=>'array']]);
        Bus::fake();HookManager::reset();admin_setting(['subscribe_url'=>'','show_info_to_server_enable'=>false,'show_protocol_to_server_enable'=>false,'app_name'=>'Link Fixture']);
        $this->plan=Plan::create(['name'=>'Link plan','group_id'=>1,'transfer_enable'=>1,'prices'=>['monthly'=>2],
            'reset_traffic_method'=>Plan::RESET_TRAFFIC_MONTHLY]);
        Server::create(['name'=>'Link server','type'=>'vmess','host'=>'link.fixture.invalid','port'=>'443','server_port'=>443,'rate'=>1,
            'group_ids'=>['1'],'enabled'=>true,'show'=>true,'protocol_settings'=>['network'=>'tcp','tls'=>0]]);
        HookManager::register('client.subscribe.before',function():void{$this->observed=request()->attributes->get(LinkService::ATTRIBUTE);});
        HookManager::registerFilter('client.subscribe.servers',function($servers){$this->rendered=request()->attributes->get(LinkService::RENDER_ATTRIBUTE);return $servers;});
    }
    protected function tearDown():void
    {
        HookManager::reset();foreach(glob($this->directory.'/*')?:[]as$file)unlink($file);rmdir($this->directory);DB::purge(DB::getDefaultConnection());parent::tearDown();
    }
    public function test_exact_binary_hmac_domain_and_key_metadata_are_canonical_and_private():void
    {
        $key=KeyMaterial::load();$token='Private Token-ABC';
        $this->assertSame(hash_hmac('sha256',"txboard.legacy-subscription-link.token/v1\0".pack('N',strlen($token)).$token,hex2bin(str_repeat('1a',32))),$key->digest($token));
        $this->assertNotSame($key->digest($token),$key->digest(strtolower($token)));
        $this->assertNotSame($key->digest($token),$key->digest($token.' '));
        $this->assertEqualsCanonicalizing(['version','key_id','generation','key_check'],array_keys($key->context()));
        foreach(['',' ',str_repeat('x',4097),"\xff"]as$bad)$this->refused(fn()=>$key->digest($bad),'LINK_TOKEN_INVALID');
        $this->assertStringNotContainsString(str_repeat('1a',32),print_r($key,true));
    }
    public function test_private_key_file_shape_permissions_duplicate_keys_and_generation_are_refused():void
    {
        $this->assertInstanceOf(KeyMaterial::class,KeyMaterial::load());
        chmod($this->directory.'/key.json',0644);$this->refused(fn()=>KeyMaterial::load(),'LINK_KEY_FILE_INVALID');
        chmod($this->directory.'/key.json',0600);$this->assertInstanceOf(KeyMaterial::class,KeyMaterial::load());
        foreach([['generation'=>1],['generation'=>'01'],['key_hex'=>'private'],['extra'=>'private'],['version'=>'wrong']]as$patch){
            $this->keyFile($patch);$this->refused(fn()=>KeyMaterial::load(),'LINK_KEY_DATA_INVALID');
        }
        $this->keyFile();$bytes=file_get_contents($this->directory.'/key.json');file_put_contents($this->directory.'/key.json',str_replace('"version":','"version":"duplicate","version":',$bytes));
        $this->refused(fn()=>KeyMaterial::load(),'LINK_KEY_DATA_INVALID');
    }
    public function test_native_http_body_headers_and_secret_uuid_rendering_are_identical_with_typed_shadow_link():void
    {
        $user=$this->user();$this->backfill();config(['legacy_subscription_links.enabled'=>false]);$native=$this->render($user);$this->assertNull($this->observed);
        config(['legacy_subscription_links.enabled'=>true]);$before=$this->rows();$mapped=$this->render($user);
        $this->assertSame($native,$mapped);$this->assertInstanceOf(LinkAssociation::class,$this->observed);$this->assertSame($this->observed,$this->rendered);
        $dto=$this->observed->jsonSerialize();$this->assertFalse($dto['authorizes']);$this->assertSame((string)$user->id,$dto['legacy_user_id']);
        $this->assertSame($before,$this->rows());$evidence=json_encode($before+$dto);
        foreach([$user->token,$user->uuid,$user->password,$user->email,'Bearer ','key_hex','key_file']as$secret)$this->assertStringNotContainsString($secret,$evidence);
    }
    public function test_disabled_observer_has_zero_adapter_sql_and_no_key_io_even_when_schema_is_missing():void
    {
        $user=$this->user();config(['legacy_subscription_links.enabled'=>false,'legacy_subscription_links.key_file'=>'/missing/private-key']);
        Schema::drop('tx_legacy_subscription_link_shadow');DB::enableQueryLog();DB::flushQueryLog();$this->render($user);$queries=DB::getQueryLog();DB::disableQueryLog();
        $this->assertNull($this->observed);foreach($queries as$q)$this->assertStringNotContainsString('tx_legacy_link',$q['query']);
        foreach($queries as$q)$this->assertStringNotContainsString('tx_legacy_subscription_link_shadow',$q['query']);
    }
    public function test_strict_environment_gate_blocks_shadow_only_and_preserves_original_http():void
    {
        $user=$this->user();$this->backfill();config(['legacy_subscription_links.enabled'=>false]);$native=$this->render($user);
        foreach(['true',1,null]as$bad){config(['legacy_subscription_links.enabled'=>$bad]);$this->assertSame($native,$this->render($user));$this->assertSame('LINK_ISOLATED_REQUIRED',$this->observed['error_code']);}
        config(['legacy_subscription_links.enabled'=>true,'core_shadow.isolated_copy'=>false]);$this->assertSame($native,$this->render($user));
        config(['core_shadow.isolated_copy'=>true]);$this->app->instance('env','production');$this->assertSame($native,$this->render($user));
    }
    public function test_native_query_token_precedence_is_the_only_authentication_and_digest_is_never_a_bearer():void
    {
        $one=$this->user();$two=$this->user();$this->backfill();Auth::forgetGuards();
        $this->get($this->url($one).'?token='.$two->token,['User-Agent'=>'v2rayn/6.40'])->assertOk();
        $this->assertSame((string)$two->id,$this->observed->userId);
        $digest=DB::table('tx_legacy_subscription_link_shadow')->where('legacy_user_id',$one->id)->value('token_digest');
        Auth::forgetGuards();$this->get('/s/'.$digest)->assertStatus(403);
    }
    public function test_native_rotation_refuses_immutable_link_rebinding_without_reissuing_url():void
    {
        $user=$this->user();$this->backfill();$old=$user->token;$oldUuid=$user->uuid;$before=$this->rows();
        \Laravel\Sanctum\Sanctum::actingAs($user);$this->postJson('/txapi/me/subscription-credentials/rotate')->assertOk();$user->refresh();
        $this->assertNotSame($old,$user->token);$this->assertNotSame($oldUuid,$user->uuid);
        Auth::forgetGuards();$this->get('/s/'.$old)->assertStatus(403);
        $this->render($user->fresh());$this->assertSame('blocked',$this->observed['status']);$this->assertSame('LINK_SOURCE_OR_CONTEXT_CHANGED',$this->observed['error_code']);
        $report=$this->service()->reconcile('links');$this->assertFalse($report['page_consistent']);$this->assertSame('LINK_SOURCE_OR_CONTEXT_CHANGED',$report['issues'][0]['code']);
        $this->assertSame($before['links'],$this->rows()['links']);
        $again=$this->service()->backfill('second','mapped');$this->assertSame('blocked',$again['phase']);$this->assertSame($before['links'],$this->rows()['links']);
    }
    public function test_duplicate_native_tokens_and_two_subscription_owners_never_map_ambiguously():void
    {
        $one=$this->user();$two=$this->user(['token'=>$one->token]);$this->mapUsers();$report=$this->service()->backfill('links','mapped');
        $this->assertSame('blocked',$report['phase']);$this->assertDatabaseCount('tx_legacy_subscription_link_shadow',0);
        foreach($report['results']as$result)$this->assertSame('LINK_TOKEN_AMBIGUOUS',$result['error_code']);
        $two->update(['token'=>'different-private-link']);$this->service()->backfill('links','mapped',100,$one->id);$this->service()->backfill('links','mapped',100,$two->id);
        $this->assertDatabaseCount('tx_legacy_subscription_link_shadow',2);
        DB::table('tx_subscription')->insert(['user_id'=>$one->id,'plan_id'=>null,'status'=>'pending','current_terms_id'=>null,
            'starts_at'=>time(),'expires_at'=>null,'ended_at'=>null,'suspended_reason'=>null,'created_at'=>time(),'updated_at'=>time(),'row_version'=>1]);
        $this->refused(fn()=>$this->service()->observe($one),'LINK_SUBSCRIPTION_AMBIGUOUS');
    }
    public function test_fixed_high_water_restart_page_retry_and_late_user_report_do_not_enroll_new_users():void
    {
        $one=$this->user();$two=$this->user();$this->mapUsers();$first=$this->service()->backfill('links','mapped',1);
        $this->assertSame((string)$one->id,$first['cursor_user_id']);$this->assertSame('backfilling',$first['phase']);
        $second=$this->service()->backfill('links','mapped',1);$this->assertSame('backfilled',$second['phase']);$rows=$this->rows();
        $this->service()->backfill('links','mapped',1);$this->assertSame($rows,$this->rows());
        $late=$this->user();$report=$this->service()->backfill('links','mapped');$this->assertTrue($report['high_water_changed']);$this->assertDatabaseCount('tx_legacy_subscription_link_shadow',2);
        $this->refused(fn()=>$this->service()->backfill('links','mapped',1,$late->id),'LINK_RETRY_OUTSIDE_HIGH_WATER');
        $this->refused(fn()=>$this->service()->backfill('links','mapped',1,$one->id),'LINK_RETRY_EXCEPTION_MISSING');
        $page=$this->service()->reconcile('links',1);$this->assertFalse($page['complete']);$this->assertSame((string)$one->id,$page['next_cursor']);
        $tail=$this->service()->reconcile('links',1,$one->id);$this->assertTrue($tail['complete']);$this->assertFalse($tail['page_consistent']);
    }
    public function test_same_metadata_changed_key_bytes_and_context_high_water_tamper_fail_without_replacement():void
    {
        $user=$this->user();$this->backfill();$before=$this->rows();$this->keyFile(['key_hex'=>str_repeat('2b',32)]);
        $this->refused(fn()=>$this->service()->reconcile('links'),'LINK_CHECKPOINT_CHANGED');$this->refused(fn()=>$this->service()->observe($user),'LINK_SOURCE_OR_CONTEXT_CHANGED');
        $this->assertSame($before,$this->rows());$this->keyFile();DB::table('tx_legacy_link_checkpoint')->update(['high_water_user_id'=>99]);
        $this->refused(fn()=>$this->service()->reconcile('links'),'LINK_CHECKPOINT_CHANGED');
    }
    public function test_digest_and_content_corruption_are_refused_without_mutation_and_sql_failures_are_secret_free():void
    {
        $user=$this->user();$this->backfill();$before=$this->rows();DB::table('tx_legacy_subscription_link_shadow')->update(['token_digest'=>str_repeat('0',64)]);
        $this->refused(fn()=>$this->service()->observe($user),'LINK_SOURCE_OR_CONTEXT_CHANGED');$this->assertNotSame($before['links'],$this->rows()['links']);
        Schema::drop('tx_legacy_subscription_link_shadow');Log::shouldReceive('error')->never();$response=$this->render($user);
        $this->assertSame('LINK_STORAGE_OR_EVIDENCE_FAILED',$this->observed['error_code']);
        $this->assertStringNotContainsString('LINK_STORAGE',$response['body']);
    }
    public function test_read_only_reconcile_uses_selects_with_no_secret_bindings_and_does_not_write_any_link():void
    {
        $user=$this->user();$this->backfill();$before=$this->rows();DB::enableQueryLog();DB::flushQueryLog();$report=$this->service()->reconcile('links');$queries=DB::getQueryLog();DB::disableQueryLog();
        $this->assertTrue($report['read_only']);$this->assertTrue($report['page_consistent']);$this->assertFalse($report['migration_gate_open']);$this->assertSame($before,$this->rows());
        foreach($queries as$q){$this->assertMatchesRegularExpression('/^select/i',$q['query']);foreach($q['bindings']as$value)$this->assertNotSame($user->token,$value);}
        foreach([$user->token,$user->uuid,$user->password,$user->email]as$secret)$this->assertStringNotContainsString($secret,json_encode($report));
    }
    public function test_expired_banned_and_exhausted_native_behavior_is_preserved_without_new_grants():void
    {
        foreach([['expired_at'=>time()-1],['banned'=>true],['u'=>10001],['transfer_enable'=>0]]as$patch){
            $user=$this->user($patch);$this->backfillNew();config(['legacy_subscription_links.enabled'=>false]);$code=(!$user->banned&&$user->transfer_enable&&$user->expired_at>time())?200:403;
            $native=$this->render($user,$code);config(['legacy_subscription_links.enabled'=>true]);$mapped=$this->render($user,$code);$this->assertSame($native,$mapped);
            if($code===403)$this->assertNull($this->rendered);else$this->assertInstanceOf(LinkAssociation::class,$this->rendered);
        }
    }
    public function test_populated_migration_replay_preserves_digests_and_down_refuses_history():void
    {
        $this->user();$this->backfill();$before=$this->rows();$migration=require base_path('database/migrations/2026_10_14_000004_create_legacy_subscription_link_shadow.php');
        $migration->up();$this->assertSame($before,$this->rows());
        try{$migration->down();$this->fail('Populated history dropped');}catch(\RuntimeException$e){$this->assertSame('LINK_POPULATED_ROLLBACK_REFUSED',$e->getMessage());}
        $this->assertSame($before,$this->rows());
    }
    public function test_real_mysql_json_reordering_preserves_digest_evidence_hashes():void
    {
        if(!$this->mysql())$this->markTestSkipped('Requires native MySQL JSON storage');
        $user=$this->user();$this->backfill();$row=DB::table('tx_legacy_subscription_link_shadow')->first();$hash=$row->content_hash;
        DB::table('tx_legacy_subscription_link_shadow')->update(['context'=>json_encode(array_reverse(json_decode($row->context,true),true))]);
        $cp=DB::table('tx_legacy_link_checkpoint')->first();DB::table('tx_legacy_link_checkpoint')->update(['context'=>json_encode(array_reverse(json_decode($cp->context,true),true))]);
        $this->assertTrue($this->service()->reconcile('links')['page_consistent']);$this->assertSame($hash,DB::table('tx_legacy_subscription_link_shadow')->value('content_hash'));
        $this->assertInstanceOf(LinkAssociation::class,$this->service()->observe($user));
    }
    public function test_raw_duplicate_context_is_refused_and_normalized_storage_keeps_closed_semantics():void
    {
        $user=$this->user();$this->backfill();$row=DB::table('tx_legacy_subscription_link_shadow')->first();
        $duplicate=str_replace('{','{"key_id":"synthetic-key",',$row->context);
        // Raw input always goes through the strict parser. Oracle MySQL's
        // binary JSON normalizes identical duplicate keys before SELECT can
        // return them; MariaDB/SQLite preserve those original bytes.
        $this->refused(fn()=>Canonical::object($duplicate),'LINK_EVIDENCE_INVALID');
        DB::table('tx_legacy_subscription_link_shadow')->update(['context'=>$duplicate]);
        $stored=DB::table('tx_legacy_subscription_link_shadow')->value('context');
        if(substr_count($stored,'"key_id"')>1){
            $this->refused(fn()=>$this->service()->observe($user),'LINK_EVIDENCE_INVALID');
        }else{
            $this->assertSame(Canonical::json(Canonical::object($row->context)),Canonical::json(Canonical::object($stored)));
            $this->assertInstanceOf(LinkAssociation::class,$this->service()->observe($user));
        }
        $context=json_decode($row->context,true);$context['private_url']='synthetic-private-url';
        DB::table('tx_legacy_subscription_link_shadow')->update(['context'=>json_encode($context)]);
        $this->refused(fn()=>$this->service()->observe($user),'LINK_SOURCE_OR_CONTEXT_CHANGED');
    }
    public function test_link_insert_failure_saves_only_fixed_exception_and_retry_is_atomic_idempotent():void
    {
        $user=$this->user();$this->mapUsers();
        DB::unprepared(DB::getDriverName()==='sqlite'
            ? "CREATE TRIGGER reject_link BEFORE INSERT ON tx_legacy_subscription_link_shadow BEGIN SELECT RAISE(ABORT,'private-token-do-not-log'); END"
            : "CREATE TRIGGER reject_link BEFORE INSERT ON tx_legacy_subscription_link_shadow FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='private-token-do-not-log'");
        $report=$this->service()->backfill('links','mapped');$this->assertSame('blocked',$report['phase']);$this->assertDatabaseCount('tx_legacy_subscription_link_shadow',0);
        $this->assertSame('LINK_STORAGE_OR_EVIDENCE_FAILED',$report['results'][0]['error_code']);
        $this->assertStringNotContainsString('private-token',json_encode($report));$this->assertStringNotContainsString('private-token',json_encode($this->rows()));
        DB::unprepared('DROP TRIGGER reject_link');$retry=$this->service()->backfill('links','mapped',100,$user->id);
        $this->assertSame('backfilled',$retry['phase']);$this->assertDatabaseCount('tx_legacy_subscription_link_shadow',1);
        $this->assertTrue($this->service()->reconcile('links')['page_consistent']);$rows=$this->rows();$this->service()->backfill('links','mapped');$this->assertSame($rows,$this->rows());
    }
    public function test_mysql_native_collation_collision_blocks_unequal_byte_digests():void
    {
        if(!$this->mysql())$this->markTestSkipped('Requires native MySQL collation');
        $first=$this->user(['token'=>str_repeat('A',32)]);$other=$this->user(['token'=>str_repeat('a',32)]);$this->mapUsers();
        $collation=DB::selectOne('SELECT COLLATION(token) AS collation FROM tx_user LIMIT 1')->collation;
        if(!str_ends_with($collation,'_ci'))$this->markTestSkipped('Native fixture collation is not case-insensitive');
        $this->assertNotSame(KeyMaterial::load()->digest($first->token),KeyMaterial::load()->digest($other->token));
        $report=$this->service()->backfill('links','mapped');$this->assertSame('blocked',$report['phase']);$this->assertDatabaseCount('tx_legacy_subscription_link_shadow',0);
        foreach($report['results']as$item)$this->assertSame('LINK_TOKEN_AMBIGUOUS',$item['error_code']);
    }
    public function test_bounds_cli_explicit_marker_and_outer_transaction_fail_closed():void
    {
        $user=$this->user();$this->backfill();$this->refused(fn()=>$this->service()->reconcile('links',0),'LINK_BOUNDS_INVALID');
        $this->refused(fn()=>$this->service()->reconcile('links',1001),'LINK_BOUNDS_INVALID');
        DB::transaction(fn()=>$this->refused(fn()=>$this->service()->observe($user),'LINK_OUTER_TRANSACTION_UNSUPPORTED'));
        $this->assertSame(1,Artisan::call('core:legacy-links-reconcile',['--run'=>'links']));$this->assertSame('LINK_ISOLATED_REQUIRED',json_decode(Artisan::output(),true)['error_code']);
        $this->assertSame(0,Artisan::call('core:legacy-links-reconcile',['--run'=>'links','--isolated-copy'=>true]));$this->assertFalse(json_decode(Artisan::output(),true)['migration_gate_open']);
    }
    private function keyFile(array $patch=[]):void
    {
        file_put_contents($this->directory.'/key.json',json_encode(array_replace(['version'=>'legacy-link-key/v1','key_id'=>'synthetic-key','generation'=>'1','key_hex'=>str_repeat('1a',32)],$patch),JSON_THROW_ON_ERROR));chmod($this->directory.'/key.json',0600);
    }
    private function user(array $patch=[]):User
    {
        $id=++$this->sequence;return User::create(array_replace(['email'=>'link-'.$id.'@example.test','password'=>'private-link-password','uuid'=>sprintf('00000000-0000-4000-8000-%012d',$id),
            'token'=>sprintf('%032x',$id),'plan_id'=>$this->plan->id,'group_id'=>1,'u'=>111,'d'=>222,'transfer_enable'=>10000,'expired_at'=>time()+86400,'banned'=>false],$patch));
    }
    private function mapUsers(string $run='mapped'):void{app(SubscriptionShadowBackfill::class)->run($run,100,['billing_timezone'=>'UTC','currency'=>'USD']);}
    private function backfill():void{$this->mapUsers();$this->service()->backfill('links','mapped');}
    private function backfillNew():void{$run='mapped-'.$this->sequence;$this->mapUsers($run);$this->service()->backfill('links-'.$this->sequence,$run);}
    private function service():LinkService{return app(LinkService::class);}
    private function rows():array
    {
        return ['links'=>DB::table('tx_legacy_subscription_link_shadow')->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all(),
            'checkpoints'=>DB::table('tx_legacy_link_checkpoint')->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all(),'exceptions'=>DB::table('tx_legacy_link_exception')->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all()];
    }
    private function url(User $user):string{return route('client.subscribe',['token'=>$user->token],false);}
    private function render(User $user,int $code=200):array
    {
        $this->observed=null;$this->rendered=null;Auth::forgetGuards();$response=$this->get($this->url($user),['User-Agent'=>'v2rayn/6.40']);$response->assertStatus($code);
        $headers=[];foreach(['content-type','subscription-userinfo','profile-title','profile-update-interval','content-disposition']as$key)$headers[$key]=$response->headers->get($key);
        return ['status'=>$response->status(),'body'=>$response->getContent(),'headers'=>$headers];
    }
    private function refused(callable $fn,string $code):void{try{$fn();$this->fail('Expected closed failure');}catch(LinkFailure$e){$this->assertSame($code,$e->getMessage());$this->assertNull($e->getPrevious());}}
    private function mysql():bool{return getenv('CORE_LEGACY_LINK_TEST_MYSQL')==='1';}
}
