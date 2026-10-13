<?php

namespace Tests\Feature\Subscription;

use App\Models\Plan;
use App\Models\User;
use App\Services\Subscription\LegacyAdminDeletionShadow;
use App\Services\Subscription\LegacyCaptureShadowException;
use App\Services\Subscription\LegacyLifecycleShadowReconcile;
use App\Services\Subscription\LegacyUserCreationEvidence;
use App\Services\Subscription\LegacyUserMutationCapture;
use App\Services\Subscription\SubscriptionShadowBackfill;
use App\Services\Subscription\SubscriptionShadowReconcile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyLifecycleShadowReconcileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); $connection = $this->connection();
        config(['database.default' => $connection, 'database.connections.'.$connection => $this->mysql()
            ? config('database.connections.mysql') : array_replace(config('database.connections.sqlite'), ['database' => ':memory:'])]);
        DB::purge($connection); if ($this->mysql()) $this->clearMysql();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true])); Bus::fake();
        config(['core_shadow.isolated_copy' => true, 'legacy_lifecycle_reconcile.enabled' => true,
            'legacy_admin_creation_shadow.enabled' => true, 'legacy_registration_creation_shadow.enabled' => true,
            'legacy_bootstrap_creation_shadow.enabled' => true, 'legacy_admin_deletion_shadow.enabled' => true,
            'legacy_capture_shadow.enabled' => true, 'legacy_capture_shadow.run_key' => 'lifecycle-fixture',
            'legacy_capture_shadow.billing_timezone' => 'UTC', 'legacy_capture_shadow.currency' => 'USD',
            'legacy_observer_capture.enabled' => false]);
    }
    protected function tearDown(): void
    { if ($this->mysql()) $this->clearMysql(); DB::purge($this->connection()); parent::tearDown(); }

    public function test_present_creation_and_mapping_associations_are_exact_read_only_and_not_a_gate(): void
    {
        $user = $this->created(['u' => 9007199254740993, 'd' => 4]); $this->backfill(); $before = $this->state();
        DB::enableQueryLog(); DB::flushQueryLog(); $report = $this->report();
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        foreach ($queries as $query) $this->assertMatchesRegularExpression('/\Aselect\b/i', $query['query']);
        $this->assertSame($before, $this->state()); $this->assertTrue($report['page_consistent']);
        $item = $report['items'][0]; $this->assertSame((string) $user->id, $item['legacy_user_id']);
        $this->assertSame('source_present', $item['classification']); $this->assertTrue($item['current_matches_initial']);
        $this->assertSame('active', $item['mapping']['subscription_status']); $this->assertTrue($item['mapping']['slot_occupied']);
        $this->assertNull($item['first_mutation_before_matches_initial']);
        foreach (['mutation_chain_verified','global_coverage_complete','projection_applied','migration_gate_open'] as $key)
            $this->assertFalse($report[$key]);
        foreach ([$user->email,$user->uuid,$user->token,'9007199254740993'] as $secret)
            $this->assertStringNotContainsString($secret,json_encode($report));
    }

    public function test_known_deletion_with_active_projection_keeps_source_missing_blocked_and_history_unchanged(): void
    {
        $user = $this->created(); $this->backfill(); $this->deleteCaptured($user->id); $before = $this->state();
        $report = $this->report(); $this->assertFalse($report['page_consistent']);
        $item = $report['items'][0]; $this->assertSame('known_admin_deletion',$item['classification']);
        $this->assertSame('active',$item['mapping']['subscription_status']); $this->assertTrue($item['mapping']['slot_occupied']);
        $this->assertNotNull($item['creation_evidence']); $this->assertNotNull($item['deletion_evidence']);
        $this->assertContains('LEGACY_USER_MISSING',$item['issues']);
        $original = app(SubscriptionShadowReconcile::class)->run('mapped'); $this->assertFalse($original['passed']);
        $this->assertContains('LEGACY_USER_MISSING',array_column($original['differences'],'code'));
        $this->assertSame('known_admin_deletion',$original['lifecycle']['items'][0]['classification']);
        $this->assertSame($before,$this->state());
    }

    public function test_unknown_missing_and_older_present_accounts_never_invent_lifecycle_history(): void
    {
        $one = $this->user(); $two = $this->user(); $this->backfill();
        DB::table('tx_user')->where('id',$one->id)->delete(); $report = $this->report();
        $this->assertSame('source_missing_unknown',$report['items'][0]['classification']);
        $this->assertNull($report['items'][0]['creation_evidence']); $this->assertNull($report['items'][0]['deletion_evidence']);
        $this->assertSame('source_present',$report['items'][1]['classification']);
        $this->assertNull($report['items'][1]['current_matches_initial']);
        $this->assertFalse($report['page_consistent']); $this->assertSame((string)$two->id,$report['next_cursor']);
    }

    public function test_creation_and_deletion_orphans_are_in_ordered_union_and_higher_evidence_changes_high_water(): void
    {
        $one=$this->created();$two=$this->created();$this->backfill();$this->deleteCaptured($one->id);
        DB::table('tx_legacy_subscription_map')->where('legacy_user_id',$one->id)->delete();
        $first=$this->report(1);$this->assertSame((string)$one->id,$first['next_cursor']);$this->assertFalse($first['complete']);
        $this->assertContains('MAPPING_MISSING',$first['items'][0]['issues']);
        $second=$this->report(1,$one->id);$this->assertSame((string)$two->id,$second['next_cursor']);$this->assertTrue($second['complete']);
        $late=$this->created();$this->deleteCaptured($late->id);$report=$this->report();
        $this->assertContains('HIGH_WATER_CHANGED',$report['range_issues']);$this->assertCount(2,$report['items']);
        $last=$this->report(1,$two->id);$this->assertSame((string)$two->id,$last['next_cursor']);$this->assertSame([],$last['items']);
    }

    public function test_all_three_closed_creation_policies_are_checked_without_enabling_their_writers(): void
    {
        $this->created([], 'admin.user.create');$this->created([], 'user.register');$this->created([], 'install.admin.create');$this->backfill();
        config(['legacy_admin_creation_shadow.enabled'=>false,'legacy_registration_creation_shadow.enabled'=>false,'legacy_bootstrap_creation_shadow.enabled'=>false]);
        $report=$this->report();$this->assertTrue($report['page_consistent']);
        $this->assertSame(['admin.user.create','user.register','install.admin.create'],array_column(array_column($report['items'],'creation_evidence'),'source_kind'));
    }

    public function test_captured_source_change_and_first_event_are_observations_not_complete_chain_verification(): void
    {
        $user=$this->created();$this->backfill();
        DB::transaction(function()use($user):void{
            DB::table('tx_user')->where('id',$user->id)->lockForUpdate()->first();$capture=app(LegacyUserMutationCapture::class);
            $before=$capture->begin($user->id);DB::table('tx_user')->where('id',$user->id)->update(['u'=>55]);$capture->append($before,1);
        });
        $report=$this->report();$this->assertFalse($report['items'][0]['current_matches_initial']);
        $this->assertTrue($report['items'][0]['first_mutation_before_matches_initial']);$this->assertFalse($report['mutation_chain_verified']);
        $this->assertFalse(app(SubscriptionShadowReconcile::class)->run('mapped')['passed']);
    }

    public function test_corrupt_creation_and_deletion_never_downgrade_to_missing_evidence(): void
    {
        $user=$this->created();$this->backfill();
        $row=DB::table('tx_legacy_user_creation_shadow')->first();
        DB::table('tx_legacy_user_creation_shadow')->update(['initial_hash'=>str_repeat('0',64)]);
        $this->refused(fn()=>$this->report(),'LIFECYCLE_EVIDENCE_INVALID');
        DB::table('tx_legacy_user_creation_shadow')->update(['initial_hash'=>$row->initial_hash]);
        $this->deleteCaptured($user->id);DB::table('tx_legacy_user_deletion_shadow')->update(['deletion_confirmed'=>false]);
        $this->refused(fn()=>$this->report(),'LIFECYCLE_EVIDENCE_INVALID');
    }

    public function test_unknown_policy_and_context_drift_are_refused_with_fixed_safe_codes(): void
    {
        $user=$this->created();$this->backfill();
        DB::table('tx_legacy_user_creation_shadow')->update(['source_kind'=>'plugin.untrusted']);
        $this->refused(fn()=>$this->report(),'LIFECYCLE_EVIDENCE_INVALID');
        DB::table('tx_legacy_user_creation_shadow')->update(['source_kind'=>'admin.user.create']);
        config(['legacy_capture_shadow.run_key'=>'other-fixture']);$this->refused(fn()=>$this->report(),'LIFECYCLE_EVIDENCE_INVALID');
    }

    public function test_storage_missing_is_refused_and_default_off_existing_reconcile_has_no_evidence_reads(): void
    {
        $this->created();$this->backfill();Schema::drop('tx_legacy_user_deletion_shadow');
        $this->refused(fn()=>$this->report(),'LIFECYCLE_STORAGE_OR_EVIDENCE_FAILED');
        config(['legacy_lifecycle_reconcile.enabled'=>false,'legacy_capture_shadow.enabled'=>'invalid']);
        DB::enableQueryLog();DB::flushQueryLog();$report=app(SubscriptionShadowReconcile::class)->run('mapped');$queries=DB::getQueryLog();DB::disableQueryLog();
        $this->assertTrue($report['passed']);$this->assertArrayNotHasKey('lifecycle',$report);
        foreach($queries as $query)$this->assertStringNotContainsString('tx_legacy_user_creation_shadow',$query['query']);
    }

    public function test_reappeared_user_and_dangling_subscription_ownership_remain_explicit_blockers(): void
    {
        $user=$this->created();$original=(array)DB::table('tx_user')->where('id',$user->id)->first();$this->backfill();$this->deleteCaptured($user->id);
        DB::table('tx_user')->insert($original);$report=$this->report();$this->assertSame('source_reappeared',$report['items'][0]['classification']);
        $this->assertContains('LIFECYCLE_SOURCE_REAPPEARED',$report['items'][0]['issues']);
        Schema::disableForeignKeyConstraints();
        try { DB::table('tx_subscription')->update(['user_id'=>$user->id+100]); }
        finally { Schema::enableForeignKeyConstraints(); }
        $this->assertContains('LIFECYCLE_MAPPING_INVALID',$this->report()['items'][0]['issues']);
    }

    public function test_bounds_outer_snapshot_production_and_ambiguous_enable_are_refused(): void
    {
        $this->created();$this->backfill();$service=app(LegacyLifecycleShadowReconcile::class);
        $this->refused(fn()=>$service->run('mapped',0),'LIFECYCLE_BOUNDS_INVALID');
        $this->refused(fn()=>$service->run('mapped',1001),'LIFECYCLE_BOUNDS_INVALID');
        $this->refused(fn()=>$service->run('mapped',1,2),'LIFECYCLE_CURSOR_OUTSIDE_HIGH_WATER');
        $this->refused(fn()=>$service->run('unknown'),'LIFECYCLE_RUN_MISSING');
        DB::transaction(fn()=>$this->refused(fn()=>$this->report(),'LIFECYCLE_OUTER_TRANSACTION_UNSUPPORTED'));
        config(['legacy_lifecycle_reconcile.enabled'=>'true']);$this->refused(fn()=>$this->report(),'LIFECYCLE_ISOLATED_REQUIRED');
        config(['legacy_lifecycle_reconcile.enabled'=>true]);$this->app['env']='production';
        $this->refused(fn()=>$this->report(),'CAPTURE_ISOLATED_COPY_REQUIRED');
    }

    public function test_cli_marker_page_and_failure_exit_have_no_authority_or_credential_leak(): void
    {
        $this->created();$this->backfill();
        $this->assertSame(1,Artisan::call('core:lifecycle-shadow-reconcile',['--run'=>'mapped']));
        $this->assertSame('LIFECYCLE_ISOLATED_REQUIRED',json_decode(Artisan::output(),true)['error_code']);
        $this->assertSame(0,Artisan::call('core:lifecycle-shadow-reconcile',['--run'=>'mapped','--isolated-copy'=>true]));
        $this->assertFalse(json_decode(Artisan::output(),true)['migration_gate_open']);
        DB::table('tx_user')->delete();
        $this->assertSame(1,Artisan::call('core:lifecycle-shadow-reconcile',['--run'=>'mapped','--isolated-copy'=>true]));
    }

    public function test_real_mysql_json_members_can_reorder_without_replacing_either_history(): void
    {
        if(!$this->mysql())$this->markTestSkipped('Requires native MySQL JSON storage.');
        $user=$this->created();$this->backfill();$this->deleteCaptured($user->id);$hashes=[];
        foreach(['tx_legacy_user_creation_shadow'=>'initial_snapshot','tx_legacy_user_deletion_shadow'=>'admitted_snapshot']as$table=>$key){
            $row=DB::table($table)->first();$hashes[]=$row->content_hash;
            DB::table($table)->update([$key=>json_encode(array_reverse(json_decode($row->$key,true),true)),
                'context'=>json_encode(array_reverse(json_decode($row->context,true),true))]);
        }
        $report=$this->report();$this->assertSame('known_admin_deletion',$report['items'][0]['classification']);
        $this->assertSame($hashes,array_column([$report['items'][0]['creation_evidence'],$report['items'][0]['deletion_evidence']],'content_hash'));
    }

    public function test_internal_page_rejects_duplicate_or_descending_ids_and_foreign_mapping_context(): void
    {
        $one=$this->created();$two=$this->created();$this->backfill();
        $context=['mapping_version'=>'subscription-shadow/v1','billing_timezone'=>'UTC','currency'=>'USD'];
        DB::transaction(function()use($one,$two,$context):void{
            $service=app(LegacyLifecycleShadowReconcile::class);
            $this->refused(fn()=>$service->page([$one->id,$one->id],$context),'LIFECYCLE_IDS_NOT_ORDERED_UNIQUE');
            $this->refused(fn()=>$service->page([$two->id,$one->id],$context),'LIFECYCLE_IDS_NOT_ORDERED_UNIQUE');
            $this->refused(fn()=>$service->page([$one->id],array_replace($context,['currency'=>'EUR'])),'LIFECYCLE_MAPPING_CONTEXT_INVALID');
        });
        DB::table('tx_migration_checkpoint')->update(['details'=>json_encode(array_replace($context,['billing_timezone'=>'Asia/Shanghai']))]);
        $this->refused(fn()=>$this->report(),'LIFECYCLE_MAPPING_CONTEXT_INVALID');
    }

    public function test_uncaptured_initial_gap_and_missing_first_event_are_explicit_and_bounded(): void
    {
        $user=$this->created();$this->backfill();DB::table('tx_user')->where('id',$user->id)->update(['u'=>2]);
        DB::transaction(function()use($user):void{
            DB::table('tx_user')->where('id',$user->id)->lockForUpdate()->first();$capture=app(LegacyUserMutationCapture::class);
            $before=$capture->begin($user->id);DB::table('tx_user')->where('id',$user->id)->update(['u'=>3]);$capture->append($before,1);
        });
        $report=$this->report();$this->assertFalse($report['page_consistent']);
        $this->assertFalse($report['items'][0]['first_mutation_before_matches_initial']);
        $this->assertContains('LIFECYCLE_INITIAL_MUTATION_MISMATCH',$report['items'][0]['issues']);
        DB::table('tx_legacy_capture_shadow_event')->delete();
        $this->refused(fn()=>$this->report(),'CAPTURE_CHAIN_GAP');
    }

    public function test_source_change_between_pages_remains_visible_and_never_updates_previous_anchors(): void
    {
        $one=$this->created();$two=$this->created();$this->backfill();$initial=$this->state();
        $first=$this->report(1);DB::table('tx_user')->where('id',$two->id)->update(['u'=>7]);
        $second=$this->report(1,(int)$first['next_cursor']);$this->assertTrue($second['complete']);
        $this->assertFalse($second['items'][0]['current_matches_initial']);$this->assertFalse($second['projection_verified']);
        $this->assertSame($initial['tx_legacy_user_creation_shadow'],$this->state()['tx_legacy_user_creation_shadow']);
    }

    public function test_real_mysql_exception_committed_after_range_snapshot_is_seen_by_current_locked_read(): void
    {
        if(!$this->mysql())$this->markTestSkipped('Requires independent MySQL MVCC sessions.');
        $user=$this->created();$this->backfill();
        config(['database.connections.lifecycle_observer'=>config('database.connections.'.$this->connection())]);
        DB::purge('lifecycle_observer');$other=DB::connection('lifecycle_observer');$committed=false;
        DB::listen(function($query)use($other,$user,&$committed):void{
            if(!$committed && $query->connectionName===$this->connection() && str_contains($query->sql,'lifecycle_ids') && str_contains(strtolower($query->sql),'union')){
                $committed=true;
                $other->table('tx_legacy_subscription_exception')->insert(['legacy_user_id'=>$user->id,'run_key'=>'mapped',
                    'source_hash'=>null,'error_code'=>'SYNTHETIC_LATE_EXCEPTION','attempts'=>1,'state'=>'unresolved',
                    'created_at'=>time(),'updated_at'=>time()]);
            }
        });
        try{
            $report=$this->report();$this->assertTrue($committed);$this->assertFalse($report['page_consistent']);
            $this->assertContains('LIFECYCLE_UNRESOLVED_EXCEPTION',$report['items'][0]['issues']);
        }finally{DB::purge('lifecycle_observer');}
    }

    public function test_corrupt_mapping_ids_cannot_pass_even_when_subscription_ownership_is_intact(): void
    {
        $this->created();$this->backfill();$map=DB::table('tx_legacy_subscription_map')->first();
        $cases=[['terms_id',0]];
        if(!$this->mysql())array_push($cases,['usage_period_id',-1],['access_subject_id','1.5'],['terms_id','9223372036854775808']);
        else $cases[]=['terms_id','9223372036854775808'];
        foreach($cases as[$key,$invalid]){
            Schema::disableForeignKeyConstraints();
            try{DB::table('tx_legacy_subscription_map')->update([$key=>$invalid]);}
            finally{Schema::enableForeignKeyConstraints();}
            $this->refused(fn()=>$this->report(),'LIFECYCLE_MAPPING_INVALID');
            Schema::disableForeignKeyConstraints();
            try{DB::table('tx_legacy_subscription_map')->update([$key=>$map->$key]);}
            finally{Schema::enableForeignKeyConstraints();}
        }
        $this->assertTrue($this->report()['page_consistent']);
    }

    public function test_invalid_or_unrelated_slot_identity_cannot_be_reported_as_a_valid_association(): void
    {
        $user=$this->created();$this->backfill();$map=DB::table('tx_legacy_subscription_map')->first();
        Schema::disableForeignKeyConstraints();
        try{DB::table('tx_subscription_slot')->where('user_id',$user->id)->update(['subscription_id'=>0]);}
        finally{Schema::enableForeignKeyConstraints();}
        $this->refused(fn()=>$this->report(),'LIFECYCLE_MAPPING_INVALID');
        Schema::disableForeignKeyConstraints();
        try{DB::table('tx_subscription_slot')->where('user_id',$user->id)->update(['subscription_id'=>(int)$map->subscription_id+100]);}
        finally{Schema::enableForeignKeyConstraints();}
        $report=$this->report();$this->assertFalse($report['page_consistent']);
        $this->assertContains('LIFECYCLE_MAPPING_INVALID',$report['items'][0]['issues']);
    }

    public function test_recomputed_hashes_do_not_make_illegal_snapshot_or_wrong_self_actor_valid(): void
    {
        $user=$this->created([], 'user.register');$this->backfill();
        $original=(array)DB::table('tx_legacy_user_creation_shadow')->first();$bad=$original;
        $snapshot=json_decode($bad['initial_snapshot'],true);$snapshot['u']='-1';
        $bad['initial_snapshot']=json_encode($snapshot);$bad['initial_hash']=$this->evidenceHash($snapshot);
        DB::table('tx_legacy_user_creation_shadow')->update($this->rehashCreation($bad));
        $this->refused(fn()=>$this->report(),'CAPTURE_SOURCE_INVALID');
        $bad=$original;$bad['actor_id']=$user->id+1;
        DB::table('tx_legacy_user_creation_shadow')->update($this->rehashCreation($bad));
        $this->refused(fn()=>$this->report(),'LIFECYCLE_EVIDENCE_INVALID');
        DB::table('tx_legacy_user_creation_shadow')->update($original);
        $this->assertTrue($this->report()['page_consistent']);
    }

    private function rehashCreation(array $row):array
    {
        $payload=$row;unset($payload['id'],$payload['content_hash']);
        foreach(['legacy_user_id','actor_id','captured_at']as$key)$payload[$key]=(string)$payload[$key];
        foreach(['context','initial_snapshot']as$key)$payload[$key]=json_decode($payload[$key],true);
        $row['content_hash']=$this->evidenceHash($payload);return $row;
    }
    private function evidenceHash(array $value):string
    {
        $normalize=function(mixed $item)use(&$normalize):mixed{
            if(!is_array($item))return $item;if(!array_is_list($item))ksort($item,SORT_STRING);return array_map($normalize,$item);
        };
        return hash('sha256',json_encode($normalize($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }

    private function report(int $limit=100,int $after=0):array{return app(LegacyLifecycleShadowReconcile::class)->run('mapped',$limit,$after);}
    private function backfill():void{app(SubscriptionShadowBackfill::class)->run('mapped',100,['billing_timezone'=>'UTC','currency'=>'USD']);}
    private function user(array $attributes=[]):User
    {
        $plan=Plan::create(['name'=>'Lifecycle fixture','group_id'=>1,'transfer_enable'=>1,'prices'=>['monthly'=>1],'reset_traffic_method'=>1]);
        return User::withoutEvents(fn()=>User::create(array_replace(['email'=>bin2hex(random_bytes(6)).'@example.test',
            'password'=>'synthetic-no-login','uuid'=>bin2hex(random_bytes(16)),'token'=>bin2hex(random_bytes(16)),
            'plan_id'=>$plan->id,'group_id'=>1,'transfer_enable'=>1000,'u'=>0,'d'=>0,'balance'=>0,'commission_balance'=>0,
            'reset_count'=>0,'expired_at'=>time()+10000],$attributes)));
    }
    private function created(array $attributes=[],string $kind='admin.user.create'):User
    {
        return DB::transaction(function()use($attributes,$kind):User{
            $service=app(LegacyUserCreationEvidence::class);$ticket=$service->begin($kind,$kind==='admin.user.create'?1:0);
            $user=$this->user($attributes);$service->append($ticket,$user->id,$kind);return $user;
        });
    }
    private function deleteCaptured(int $id):void
    {
        DB::transaction(function()use($id):void{
            DB::table('tx_user')->where('id',$id)->lockForUpdate()->first();$service=app(LegacyAdminDeletionShadow::class);
            $ticket=$service->begin($id,1);DB::table('tx_user')->where('id',$id)->delete();$service->append($ticket);
        });
    }
    private function state():array
    {
        $state=[];foreach(['tx_user','tx_legacy_user_creation_shadow','tx_legacy_user_deletion_shadow','tx_legacy_capture_shadow_event',
            'tx_legacy_subscription_map','tx_subscription','tx_subscription_terms','tx_usage_period','tx_access_subject','tx_subscription_slot','tx_migration_checkpoint']as$table)
            $state[$table]=DB::table($table)->orderBy($table==='tx_subscription_slot'?'user_id':'id')->get()->toJson();return $state;
    }
    private function refused(callable $call,string $code):void
    {try{$call();$this->fail('Expected refusal');}catch(LegacyCaptureShadowException $error){$this->assertSame($code,$error->getMessage());$this->assertNull($error->getPrevious());}}
    private function mysql():bool{return getenv('CORE_LIFECYCLE_RECONCILE_TEST_MYSQL')==='1';}
    private function connection():string{return $this->mysql()?'lifecycle_mysql_testing':'lifecycle_sqlite_testing';}
    private function clearMysql():void
    {
        if(DB::connection()->getDatabaseName()!=='txboard_lifecycle_reconcile_test')throw new \RuntimeException('Lifecycle reconciliation requires its dedicated fixture database.');
        Schema::disableForeignKeyConstraints();try{foreach(Schema::getTableListing()as$table)Schema::drop($table);}finally{Schema::enableForeignKeyConstraints();}
    }
}
