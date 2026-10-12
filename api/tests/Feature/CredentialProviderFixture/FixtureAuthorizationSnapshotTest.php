<?php

namespace Tests\Feature\CredentialProviderFixture;

use App\Domains\CredentialProviderFixture\{FixtureAuthorizationCodec, FixtureAuthorizationService, FixtureAuthorizationSigner,
    FixtureAuthorizationVerifier, FixtureCodec, FixtureLeaseCandidateService, FixtureLeaseClock, FixturePluginBinding, FixtureProviderService, FixtureScope};
use App\Domains\UsageV2\{UsageError, UsageRegistry};
use App\Jobs\NodeUserSyncJob;
use App\Models\{Plugin, User};
use App\Services\Plugin\PluginManager;
use App\Services\Subscription\SubscriptionTermsShadowService;
use DomainException;
use Firebase\JWT\JWT;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, File, Log, Queue, Schema};
use RuntimeException;
use Tests\TestCase;

final class FixtureAuthorizationSnapshotTest extends TestCase
{
    private string $root;
    private array $f;
    private AuthorizationTestClock $clock;
    private const TABLES = [FixtureAuthorizationService::TABLE, FixtureLeaseCandidateService::TABLE, FixturePluginBinding::TABLE, 'tx_plugins',
        'tx_credential_provider_shadow_revocation', 'tx_credential_provider_shadow_lifecycle', 'tx_credential_provider_shadow_operation', 'tx_credential_provider_shadow_registration',
        'tx_subscription_terms_shadow_operation', 'tx_node_usage_stat', 'tx_usage_outbox', 'tx_usage_entry', 'tx_usage_batch', 'tx_auth_snapshot', 'tx_tariff_revision', 'tx_node_usage_source',
        'tx_usage_adjustment', 'tx_access_subject', 'tx_legacy_subscription_map', 'tx_legacy_subscription_exception', 'tx_migration_checkpoint',
        'tx_subscription_slot', 'tx_usage_period', 'tx_subscription_terms_transition', 'tx_subscription_terms', 'tx_subscription', 'tx_user', 'tx_server'];
    protected function setUp(): void
    {
        parent::setUp();
        $mysql = getenv('CORE_PROVIDER_AUTHORIZATION_MYSQL') === '1';
        config(['database.default' => 'authorization_testing', 'database.connections.authorization_testing' => $mysql ? config('database.connections.mysql')
            : array_merge(config('database.connections.sqlite'), ['database' => ':memory:', 'foreign_key_constraints' => true])]); DB::purge('authorization_testing');
        if ($mysql) { $this->clearDatabase(); }
        Schema::create('tx_user', function (Blueprint $t) { $t->integer('id', true); $t->string('email'); $t->string('password');
            $t->string('uuid'); $t->string('token'); $t->boolean('banned')->default(false); $t->unsignedBigInteger('u')->default(0);
            $t->unsignedBigInteger('d')->default(0); $t->unsignedBigInteger('next_reset_at')->nullable();
            $t->unsignedBigInteger('created_at'); $t->unsignedBigInteger('updated_at'); });
        Schema::create('tx_server', fn (Blueprint $t) => $t->bigIncrements('id'));
        foreach (['2025_01_18_140511_create_plugins_table.php', '2025_07_26_000001_add_type_to_plugins_table.php',
            '2026_10_12_000001_create_subscription_shadow_tables.php', '2026_10_12_000003_create_node_usage_'.'v2'.'_tables.php',
            '2026_10_12_000005_create_subscription_terms_shadow_operations.php', '2026_10_12_000007_create_credential_provider_shadow_tables.php',
            '2026_10_12_000009_create_credential_provider_lifecycle_shadow_tables.php', '2026_10_12_000011_create_provider_plugin_fixture_binding.php',
            '2026_10_12_000012_create_provider_lease_candidate_fixture.php', '2026_10_12_000013_create_provider_authorization_fixture.php'] as $file) { (require database_path('migrations/'.$file))->up(); }
        $this->root = sys_get_temp_dir().'/txboard-lease-'.bin2hex(random_bytes(8)); mkdir($this->root, 0700);
        config(['credential_provider_authorization_fixture.enabled' => true, 'credential_provider_lease_fixture.enabled' => true, 'credential_provider_fixture.enabled' => true,
            'credential_provider_fixture.plugin_bridge_enabled' => true, 'credential_provider_fixture.private_root' => $this->root,
            'credential_provider_fixture.test_fault' => null, 'core_shadow.isolated_copy' => true,
            'core_usage_v2.isolated_copy' => true, 'core_usage_v2.core_instance_id' => 'lease_core_fixture_0001', 'core_usage_v2.receipt_generation' => '1']);
        // Run the real observer and assert its dispatch; this fixture stops only the Node delivery job.
        Queue::fake([NodeUserSyncJob::class]);
        $at = time(); $user = User::create(['email' => 'lease@example.test', 'password' => password_hash('FixtureOnly2026!', PASSWORD_BCRYPT),
            'uuid' => 'legacy-fixture-marker', 'token' => 'legacy-token-marker', 'banned' => false]);
        Queue::assertPushed(NodeUserSyncJob::class); $this->assertNull($user->fresh()->next_reset_at);
        $node = DB::table('tx_server')->insertGetId([]);
        $sub = DB::table('tx_subscription')->insertGetId(['user_id' => $user->id, 'status' => 'active', 'starts_at' => $at-100,
            'expires_at' => $at+600, 'created_at' => $at, 'updated_at' => $at]);
        $terms = DB::table('tx_subscription_terms')->insertGetId(['subscription_id' => $sub, 'version_no' => 1, 'effective_from' => $at-100,
            'quota_bytes' => 1000, 'billing_timezone' => 'UTC', 'reset_policy' => '{"method":0}', 'content_hash' => str_repeat('a',64), 'created_at' => $at]);
        DB::table('tx_subscription')->where('id', $sub)->update(['current_terms_id' => $terms]);
        DB::table('tx_subscription_slot')->insert(['user_id' => $user->id, 'slot_no' => 1, 'subscription_id' => $sub, 'reserved_at' => $at, 'created_at' => $at, 'updated_at' => $at]);
        $period = DB::table('tx_usage_period')->insertGetId(['subscription_id' => $sub, 'terms_id' => $terms, 'period_no' => 1,
            'starts_at' => $at-100, 'ends_at' => $at+500, 'quota_bytes' => 1000, 'billable_bytes' => 30, 'status' => 'open',
            'boundary_kind' => 'known', 'created_at' => $at, 'updated_at' => $at]);
        $subject = DB::table('tx_access_subject')->insertGetId(['subscription_id' => $sub, 'provider_id' => 'lease_provider',
            'provider_subject_ref' => 'core_pending_fixture', 'status' => 'pending', 'auth_version' => 1, 'created_at' => $at, 'updated_at' => $at]);
        Plugin::create(['code' => FixturePluginBinding::CODE, 'name' => 'Lease fixture', 'version' => '1.0.0', 'is_enabled' => true, 'installed_at' => now()]);
        app(FixtureProviderService::class)->register('lease_provider', '1', ['issue', 'query', 'revoke']);
        app(FixturePluginBinding::class)->bind('lease_provider');
        $scope = new FixtureScope('lease_provider', (string) $subject);
        // Original issuance requires a not-before that has not passed while its SQL locks are acquired.
        $notBefore = time()+2;
        $issue = app(FixtureProviderService::class)->issue($scope, 'fixture_real_issue', ['expected_subject_version' => '1', 'credential_profile_id' => 'synthetic-v1',
            'not_before_at' => $notBefore, 'credential_expires_at' => $at+300], time()+5); $this->assertSame('succeeded', $issue['status']);
        $source = app(UsageRegistry::class)->source($node, 'lease_installation_0001', $at);
        $tariff = app(UsageRegistry::class)->tariff($node, '3', '2', $at-100, $at+500);
        $at=max(time(), $notBefore); $this->f = compact('at', 'node', 'sub', 'terms', 'period', 'subject', 'scope', 'issue', 'source', 'tariff') + ['user' => (string) $user->id];
        $this->clock = new AuthorizationTestClock(); $this->clock->at = $at;
        $this->app->instance(FixtureLeaseClock::class, $this->clock);
        $this->keys();
        $this->f['candidate']=$this->candidate();
    }
    protected function tearDown(): void
    {
        if (getenv('CORE_PROVIDER_AUTHORIZATION_MYSQL') === '1') { $this->clearDatabase(); }
        if (isset($this->root)) { File::deleteDirectory($this->root); } DB::purge('authorization_testing'); parent::tearDown();
    }
    public function test_initial_binding_and_signature_are_atomic_nonsecret_and_not_node_authorization(): void
    {
        $before=$this->unchanged(); Log::spy(); DB::enableQueryLog(); $r=$this->commit(); $queries=DB::getQueryLog(); DB::disableQueryLog();
        $subject=DB::table('tx_access_subject')->first(); $t=$r['payload']['binding_commit'];
        $this->assertSame('active',$subject->status); $this->assertSame($t['subject_ref'],$subject->provider_subject_ref);
        $this->assertSame('2',(string)$subject->auth_version); $this->assertSame('2',(string)$subject->row_version);
        $this->assertSame($before,$this->unchanged()); $this->assertFalse($r['admission_granted']); $this->assertNull($r['auth_snapshot_id']);
        $this->assertSame(0,DB::table('tx_auth_snapshot')->count()); $this->assertSame(0,DB::table('tx_usage_outbox')->count());
        $this->assertSame('0',$r['payload']['policy']['subject_revocation_fact_version']); $this->assertNull($r['payload']['policy']['node_revocation_version']);
        $this->assertSame($this->f['at']+60,$r['payload']['lease_expires_at']);
        $this->assertTrue($this->verifier()->verifyWindow($r['signed_snapshot'])['signature_verified']);
        $this->assertSame('valid',$this->check($r)['local_validation']['state']);
        $this->assertSame($r['signed_snapshot'],$this->commit()['signed_snapshot']); $this->assertSame(1,DB::table(FixtureAuthorizationService::TABLE)->count());
        $this->refused(fn()=>$this->commit('fixture_other'),'AUTHORIZATION_ALREADY_COMMITTED');
        $this->assertSame($this->f['candidate']['evidence'],app(FixtureLeaseCandidateService::class)->inspect($this->f['scope'],$this->f['candidate']['candidate_ref'])['evidence']);
        $this->assertSame('refused',app(FixtureLeaseCandidateService::class)->checkCurrent($this->f['scope'],$this->f['candidate']['candidate_ref'])['local_validation']['state']);
        $this->assertSame('valid',$this->check($r)['local_validation']['state']); // Original candidate becomes stale, immutable proof still matches.
        foreach($queries as $q) { $this->assertStringNotContainsString('tx_server',$q['query']); }
        $secret=json_decode(file_get_contents($this->root.'/lease_provider/state.json'),true)['operations'][$this->f['issue']['operation_ref']]['fixture_secret'];
        foreach([$secret,file_get_contents($this->root.'/signer.pem'),'legacy-token-marker'] as $s) {
            $this->assertStringNotContainsString($s,json_encode($r)); $this->assertStringNotContainsString($s,DB::table(FixtureAuthorizationService::TABLE)->get()->toJson());
            $this->assertStringNotContainsString($s,json_encode($queries));
        }
        Log::shouldNotHaveReceived('error'); Log::shouldNotHaveReceived('warning');
        // Standalone verifier uses only vendor/classes + a public-only trust file, no Laravel bootstrap/DB/Provider secret.
        $public=$this->trust; $public['keys'][0]['private_key_path']=null; $path=$this->root.'/offline-trust.json';
        file_put_contents($path,FixtureCodec::json($public)); $token=$this->root.'/snapshot.jws'; file_put_contents($token,$r['signed_snapshot']);
        $process=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('tests/Fixtures/CredentialProviderAuthorization/verify.php'),$path,$token]);
        $process->setTimeout(5); $process->mustRun(); $verified=json_decode($process->getOutput(),true);
        $this->assertTrue($verified['signature_verified']); $this->assertFalse($verified['admission_granted']);
        $this->assertSame('not_evaluated',$verified['live_core_eligibility']); $this->assertStringNotContainsString($secret,$process->getOutput());
    }
    public function test_default_off_production_outer_transaction_and_unknown_schema_are_closed(): void
    {
        foreach(['credential_provider_authorization_fixture.enabled','credential_provider_lease_fixture.enabled','credential_provider_fixture.enabled',
            'credential_provider_fixture.plugin_bridge_enabled','core_shadow.isolated_copy'] as $flag) {
            config([$flag=>false]); $this->refused(fn()=>$this->commit(),'CAPABILITY_UNAVAILABLE'); config([$flag=>true]);
        }
        DB::beginTransaction(); try { $this->refused(fn()=>$this->commit(),'PROVIDER_OUTER_TRANSACTION_UNSUPPORTED'); } finally { DB::rollBack(); }
        Schema::table(FixtureAuthorizationService::TABLE,fn(Blueprint $t)=>$t->string('untrusted_extra')->nullable());
        $this->refused(fn()=>$this->commit(),'AUTHORIZATION_SCHEMA_UNKNOWN');
        Schema::table(FixtureAuthorizationService::TABLE,fn(Blueprint $t)=>$t->dropColumn('untrusted_extra'));
        $this->app->detectEnvironment(fn()=>'production'); $this->refused(fn()=>$this->commit(),'CAPABILITY_UNAVAILABLE'); $this->unbound();
    }
    public function test_only_pending_exact_placeholder_unrevoked_initial_versions_can_bind(): void
    {
        foreach([['status','active'],['provider_subject_ref','legacy-shadow-user-1'],['auth_version',2],['revocation_fact_version',1],['revoked_at',$this->f['at']]] as [$field,$value]) {
            $old=DB::table('tx_access_subject')->value($field); DB::table('tx_access_subject')->update([$field=>$value]);
            try { $this->commit(); $this->fail('Invalid initial subject accepted'); } catch(DomainException) { $this->assertSame(0,DB::table(FixtureAuthorizationService::TABLE)->count()); }
            DB::table('tx_access_subject')->update([$field=>$old]);
        }
        $this->unbound();
        $this->refused(fn()=>$this->commit('fixture_bad',"lc_".str_repeat('a',32)."\n"),'PROVIDER_INPUT_INVALID');
        $this->refused(fn()=>$this->commit("fixture_key\n"),'PROVIDER_INPUT_INVALID');
        $this->refused(fn()=>$this->commit('fixture_missing','lc_'.str_repeat('0',32)),'UNAUTHORIZED_SCOPE');
        $other=$this->candidate('fixture_second_candidate'); $this->commit();
        $this->refused(fn()=>$this->commit('fixture_authorization',$other['candidate_ref']),'IDEMPOTENCY_CONFLICT');
    }
    public function test_unknown_and_rehashed_corrupt_issue_candidate_or_owner_evidence_cannot_bind(): void
    {
        $issue=DB::table('tx_credential_provider_shadow_operation')->first(); $saved=(array)$issue;
        foreach(['extra','wrong_owner','profile','generation','expiry','rejected'] as $fault) {
            $in=json_decode($issue->input_snapshot,true); $out=json_decode($issue->result_snapshot,true); $status='succeeded';
            match($fault) { 'extra'=>$in['metadata']='forbidden','wrong_owner'=>$in['access_subject_id']='999','profile'=>$in['credential_profile_id']='legacy',
                'generation'=>$out['credential_generation']='2','expiry'=>$out['credential_expires_at']++,'rejected'=>$status='rejected' };
            DB::table('tx_credential_provider_shadow_operation')->update(['input_snapshot'=>FixtureCodec::json($in),'input_hash'=>FixtureCodec::hash($in),
                'result_snapshot'=>FixtureCodec::json($out),'result_hash'=>FixtureCodec::hash($out),'status'=>$status]);
            $this->refused(fn()=>$this->commit(),$fault==='extra'?'LEASE_EVIDENCE_INVALID':'LEASE_ISSUE_INVALID');
            DB::table('tx_credential_provider_shadow_operation')->where('id',$issue->id)->update($saved);
        }
        $candidate=DB::table(FixtureLeaseCandidateService::TABLE)->first(); $original=(array)$candidate;
        foreach(['extra','source','auth','owner','hash'] as $fault) {
            $e=json_decode($candidate->evidence_snapshot,true);
            match($fault) { 'extra'=>$e['metadata']='forbidden','source'=>$e['source_epoch']='999','auth'=>$e['auth_version']='2',
                'owner'=>$e['user_id']='999','hash'=>$e['issue_result_hash']=str_repeat('0',64) };
            DB::table(FixtureLeaseCandidateService::TABLE)->update(['evidence_snapshot'=>FixtureCodec::json($e),'evidence_hash'=>FixtureCodec::hash($e)]);
            try { $this->commit(); $this->fail('Rehashed candidate accepted'); } catch(DomainException) { $this->assertSame(0,DB::table(FixtureAuthorizationService::TABLE)->count()); }
            DB::table(FixtureLeaseCandidateService::TABLE)->where('id',$candidate->id)->update($original);
        }
        $this->unbound();
    }
    public function test_history_replay_is_original_bytes_after_disabled_deleted_rotated_expired_key_without_resigning(): void
    {
        $r=$this->commit(); app(PluginManager::class)->disable(FixturePluginBinding::CODE); DB::table('tx_user')->delete();
        DB::table('tx_node_usage_source')->update(['current'=>false]); $this->clock->at+=100;
        $this->trust['keys'][0]['revoked']=true; $this->trust['keys'][0]['private_key_path']=null; $this->writeTrust();
        $again=$this->commit(); $this->assertSame($r['signed_snapshot'],$again['signed_snapshot']);
        $this->assertSame($r['payload'],$again['payload']); $this->assertSame('not_evaluated',$again['signature_trust']); $this->assertTrue($again['historical_only']);
        $this->assertSame($again,$this->service()->inspect($this->f['scope'],$r['snapshot_ref']));
        $this->assertTrue($this->verifier()->verifySignature($r['signed_snapshot'])['signature_verified']);
        $closed=$this->check($r); $this->assertSame('expired',$closed['local_validation']['state']);
        $this->clock->at=$this->f['at']; $this->assertSame($closed['local_validation'],$this->check($r)['local_validation']);
        $this->assertSame('2',(string)DB::table('tx_access_subject')->value('auth_version'));
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('currentFaults')]
    public function test_terminal_current_refusal_is_committed_and_never_reopens(string $fault,string $expected): void
    {
        $r=$this->commit(); $this->mutate($fault); $closed=$this->check($r);
        $this->assertSame('refused',$closed['local_validation']['state']); $this->assertSame($expected,$closed['local_validation']['reason_code']);
        if($fault==='ban') { DB::table('tx_user')->update(['banned'=>false]); }
        $this->assertSame($closed['local_validation'],$this->check($r)['local_validation']);
        $this->assertSame($r['signed_snapshot'],$this->commit()['signed_snapshot']);
    }
    public static function currentFaults(): array
    {
        return [['ban','LEASE_CURRENT_DISABLED'],['reset','LEASE_PERIOD_INVALID'],['disable','PROVIDER_DISABLED'],['revoke','REVOKED'],
            ['source','LEASE_SOURCE_UNAVAILABLE'],['generation','AUTHORIZATION_BINDING_CHANGED'],['subject_version','LEASE_CURRENT_BINDING_CHANGED'],
            ['row_version','AUTHORIZATION_BINDING_CHANGED'],['quota','LEASE_SETTLED_QUOTA_EXHAUSTED'],['key','AUTHORIZATION_KEY_REVOKED'],
            ['interval','AUTHORIZATION_INTERVAL_CHANGED']];
    }
    public function test_clock_regression_and_expiry_are_terminal_and_final_elapsed_rolls_back_first_binding(): void
    {
        $r=$this->commit(); $this->clock->at+=10; $this->check($r); $this->clock->at-=5;
        $this->assertSame('AUTHORIZATION_CLOCK_REGRESSION',$this->check($r)['local_validation']['reason_code']);
        $this->clock->at+=100; $this->assertSame('refused',$this->check($r)['local_validation']['state']);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('initialFailures')]
    public function test_first_final_signing_time_or_configuration_failure_rolls_back_binding(string $fault,string $expected): void
    {
        $clock=$this->clock; $test=$this;
        $this->app->instance(FixtureAuthorizationSigner::class,new class($clock,$test,$fault) extends FixtureAuthorizationSigner {
            public function __construct(private AuthorizationTestClock $clock,private FixtureAuthorizationSnapshotTest $test,private string $fault) {}
            public function sign(array $payload,array $context): string {
                $token=parent::sign($payload,$context);
                match($this->fault) { 'elapsed'=>$this->clock->elapsed=61,'regression'=>$this->clock->at--,
                    'expiry'=>$this->clock->at+=60,'trust'=>$this->test->revokeKey(),'config_bytes'=>$this->test->changeTrustBytes(),
                    'sign'=>throw new DomainException('AUTHORIZATION_SIGNING_FAILED') };
                return $token;
            }
        });
        $this->refused(fn()=>$this->commit(),$expected); $this->unbound();
        $this->assertSame('prepared',DB::table(FixtureLeaseCandidateService::TABLE)->value('state'));
    }
    public static function initialFailures(): array
    {
        return [['elapsed','AUTHORIZATION_EXPIRED'],['regression','AUTHORIZATION_CLOCK_REGRESSION'],['expiry','AUTHORIZATION_EXPIRED'],
            ['trust','AUTHORIZATION_TRUST_UNAVAILABLE'],['config_bytes','AUTHORIZATION_TRUST_CHANGED'],['sign','AUTHORIZATION_SIGNING_FAILED']];
    }
    public function test_sql_failure_rolls_back_and_repair_replay_preserves_restrictive_history(): void
    {
        $table=FixtureAuthorizationService::TABLE;
        DB::unprepared(DB::getDriverName()==='mysql'?"CREATE TRIGGER auth_fail BEFORE INSERT ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'"
            :"CREATE TRIGGER auth_fail BEFORE INSERT ON $table BEGIN SELECT RAISE(ABORT,'fixture'); END");
        try { $this->commit(); $this->fail('Trigger accepted'); } catch(QueryException) { $this->unbound(); }
        DB::unprepared('DROP TRIGGER auth_fail'); $r=$this->commit();
        $migration=require database_path('migrations/2026_10_12_000013_create_provider_authorization_fixture.php'); $migration->up();
        $keys=Schema::getForeignKeys($table); $this->assertCount(10,$keys);
        foreach($keys as $k) { $this->assertContains($k['on_delete'],['restrict','no action']);
            if(DB::getDriverName()==='mysql') { $this->assertSame('tx_cp_auth_'.$k['columns'][0].'_fk',$k['name']); $this->assertLessThanOrEqual(64,strlen($k['name'])); } }
        try { DB::table('tx_node_usage_source')->delete(); $this->fail('History deleted'); } catch(QueryException) { $this->assertSame(1,DB::table($table)->count()); }
        if(DB::getDriverName()==='mysql') {
            DB::statement("ALTER TABLE $table DROP FOREIGN KEY tx_cp_auth_source_id_fk");
            $this->refused(fn()=>$this->commit(),'AUTHORIZATION_SCHEMA_UNKNOWN'); $migration->up(); $this->assertCount(10,Schema::getForeignKeys($table));
        }
        $this->assertSame($r['signed_snapshot'],$this->commit()['signed_snapshot']);
        $this->expectException(RuntimeException::class); $this->expectExceptionMessage('Authorization fixture history exists; fix forward or restore verified backup.'); $migration->down();
    }
    public function test_jws_algorithm_type_key_json_decimal_bounds_and_signature_mutations_reject(): void
    {
        $r=$this->commit(); $parts=explode('.',$r['signed_snapshot']);
        $header=json_decode(base64_decode(strtr($parts[0],'-_','+/')),true); $payload=$r['payload'];
        foreach(['algorithm','type','kid','extra','duplicate','tamper','audience','decimal','admission','jwt_exp','wrong_ref','time','oversize','newline'] as $fault) {
            $h=$header; $p=$payload; $sig=$parts[2];
            match($fault) { 'algorithm'=>$h['alg']='HS256','type'=>$h['typ']='JWT','kid'=>$h['kid']='unknown','extra'=>$h['jku']='https://example.test/',
                'audience'=>$p['aud']='node','decimal'=>$p['binding_commit']['auth_version']="2\n",'admission'=>$p['admission_granted']=true,
                'jwt_exp'=>$p['exp']=time()+999,'wrong_ref'=>$p['binding_commit']['subject_ref']='ps_'.str_repeat('0',32),
                'time'=>$p['lease_expires_at']++,'tamper'=>$sig[4]=$sig[4]==='A'?'B':'A', default=>null };
            $json=$fault==='duplicate'?'{"alg":"RS256","alg":"RS256","typ":"'.FixtureAuthorizationCodec::TYPE.'","kid":"fixture_key_1"}':json_encode($h);
            $token=$this->b64($json).'.'.$this->b64(json_encode($p)).'.'.$sig;
            if($fault==='oversize') { $token.=str_repeat('a',16384); } if($fault==='newline') { $token.="\n"; }
            $this->refused(fn()=>$this->verifier()->verifySignature($token),'AUTHORIZATION_SIGNATURE_INVALID');
        }
        $oldTimestamp=JWT::$timestamp; $oldLeeway=JWT::$leeway;
        JWT::$timestamp=1; JWT::$leeway=999999;
        try { $this->assertTrue($this->verifier()->verifyWindow($r['signed_snapshot'])['within_window']); $this->assertSame(1,JWT::$timestamp); }
        finally { JWT::$timestamp=$oldTimestamp; JWT::$leeway=$oldLeeway; }
        $this->clock->at+=60; $this->assertTrue($this->verifier()->verifySignature($r['signed_snapshot'])['signature_verified']);
        $this->refused(fn()=>$this->verifier()->verifyWindow($r['signed_snapshot']),'AUTHORIZATION_EXPIRED');
    }
    public function test_rehashed_stored_payload_mismatch_and_rehashed_forged_signature_do_not_pass_current_verification(): void
    {
        $r=$this->commit(); $row=DB::table(FixtureAuthorizationService::TABLE)->first();
        $p=$r['payload']; $p['policy']['settled_billable_bytes']='31'; $parts=explode('.',$row->signed_snapshot);
        $parts[1]=$this->b64(FixtureCodec::json($p)); $token=implode('.',$parts);
        DB::table(FixtureAuthorizationService::TABLE)->update(['payload_snapshot'=>FixtureCodec::json($p),'payload_hash'=>FixtureCodec::hash($p),
            'signed_snapshot'=>$token,'signature_hash'=>hash('sha256',$token)]);
        $closed=$this->check($r); $this->assertSame('AUTHORIZATION_SIGNATURE_INVALID',$closed['local_validation']['reason_code']);
        $this->assertFalse($closed['admission_granted']);
    }
    public function test_trust_private_pair_algorithm_and_bounds_fail_without_binding_or_secret_exception_args(): void
    {
        $original=file_get_contents($this->root.'/signer.pem');
        $other=openssl_pkey_new(['private_key_bits'=>3072,'private_key_type'=>OPENSSL_KEYTYPE_RSA]); openssl_pkey_export($other,$pem);
        file_put_contents($this->root.'/signer.pem',$pem); chmod($this->root.'/signer.pem',0600);
        $before=ini_get('zend.exception_ignore_args'); ini_set('zend.exception_ignore_args','0');
        try { $this->commit(); $this->fail('Mismatched pair accepted'); } catch(DomainException $e) {
            $this->assertSame('AUTHORIZATION_TRUST_UNAVAILABLE',$e->getMessage()); $this->assertStringNotContainsString($pem,(string)$e);
            $this->assertStringNotContainsString('BEGIN PRIVATE KEY',json_encode($e->getTrace()));
        } finally { ini_set('zend.exception_ignore_args',$before); }
        file_put_contents($this->root.'/signer.pem',$original); $this->trust['keys'][0]['epoch']="1\n"; $this->writeTrust();
        $this->refused(fn()=>$this->commit(),'AUTHORIZATION_TRUST_UNAVAILABLE'); $this->trust['keys'][0]['epoch']='1'; $this->writeTrust();
        $old=JWT::$supported_algs['RS256']; JWT::$supported_algs['RS256']=['openssl','SHA384'];
        try { $this->refused(fn()=>$this->commit(),'AUTHORIZATION_SIGNING_FAILED'); } finally { JWT::$supported_algs['RS256']=$old; }
        $this->unbound();
    }
    public function test_real_independent_sessions_same_key_replay_one_binding_and_different_keys_one_winner(): void
    {
        $this->requireMysql(); $results=$this->parallel([fn()=>$this->commit(),fn()=>$this->commit()]);
        $this->assertSame($results[0]['signed_snapshot'],$results[1]['signed_snapshot']); $this->assertSame(1,DB::table(FixtureAuthorizationService::TABLE)->count());
        $this->assertSame('2',(string)DB::table('tx_access_subject')->value('auth_version'));
    }
    public function test_verify_only_predecessor_and_key_removal_never_resign_original_history(): void
    {
        $r=$this->commit(); $new=openssl_pkey_new(['private_key_bits'=>3072,'private_key_type'=>OPENSSL_KEYTYPE_RSA]); openssl_pkey_export($new,$pem);
        file_put_contents($this->root.'/new.pem',$pem); chmod($this->root.'/new.pem',0600); file_put_contents($this->root.'/new-public.pem',openssl_pkey_get_details($new)['key']);
        $next=$this->trust['keys'][0]; $next['kid']='fixture_key_2'; $next['epoch']='2'; $next['spki_sha256']=FixtureAuthorizationSigner::spki($new);
        $next['public_key_path']=$this->root.'/new-public.pem'; $next['private_key_path']=$this->root.'/new.pem';
        $this->trust['keys'][0]['private_key_path']=null; $this->trust['keys'][]=$next; $this->trust['current_kid']='fixture_key_2'; $this->writeTrust();
        $this->assertSame('valid',$this->check($r)['local_validation']['state']); $this->assertSame($r['signed_snapshot'],$this->commit()['signed_snapshot']);
        array_shift($this->trust['keys']); $this->writeTrust();
        $this->assertSame('AUTHORIZATION_SIGNATURE_INVALID',$this->check($r)['local_validation']['reason_code']);
        $this->assertSame($r['signed_snapshot'],$this->commit()['signed_snapshot']);
    }
    public function test_sql_source_installation_damage_refuses_current_and_preserves_original_signature_history(): void
    {
        $r=$this->commit(); DB::table('tx_node_usage_source')->where('id',$this->f['source']->id)->update(['installation_ref'=>'damaged_installation_0001']);
        $closed=$this->check($r); $this->assertSame('refused',$closed['local_validation']['state']);
        $this->assertSame('AUTHORIZATION_BINDING_CHANGED',$closed['local_validation']['reason_code']);
        $this->assertSame('refused',DB::table(FixtureAuthorizationService::TABLE)->value('state'));
        DB::table('tx_node_usage_source')->where('id',$this->f['source']->id)->update(['installation_ref'=>'lease_installation_0001']);
        $this->assertSame($closed['local_validation'],$this->check($r)['local_validation']);
        $this->assertSame($r['signed_snapshot'],$this->commit()['signed_snapshot']); $this->assertFalse($closed['admission_granted']);
    }
    public function test_unknown_sql_fault_during_current_check_returns_no_success_and_does_not_erase_history(): void
    {
        $r=$this->commit(); $table=FixtureAuthorizationService::TABLE;
        DB::unprepared(DB::getDriverName()==='mysql'?"CREATE TRIGGER auth_update_fail BEFORE UPDATE ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'"
            :"CREATE TRIGGER auth_update_fail BEFORE UPDATE ON $table BEGIN SELECT RAISE(ABORT,'fixture'); END");
        DB::table('tx_user')->update(['banned'=>true]);
        try { $this->check($r); $this->fail('Unknown SQL fault returned success'); } catch(QueryException) { $this->assertSame('valid',DB::table($table)->value('state')); }
        DB::unprepared('DROP TRIGGER auth_update_fail'); $this->assertSame($r['signed_snapshot'],$this->commit()['signed_snapshot']);
        $this->assertSame('refused',$this->check($r)['local_validation']['state']);
    }
    public function test_real_independent_sessions_different_keys_cannot_bind_twice(): void
    {
        $this->requireMysql(); $results=$this->parallel([fn()=>$this->commit('fixture_parallel_a'),fn()=>$this->commit('fixture_parallel_b')]);
        $codes=array_map(fn($r)=>$r['error']??'SUCCESS',$results); sort($codes); $this->assertSame(['AUTHORIZATION_ALREADY_COMMITTED','SUCCESS'],$codes);
        $this->assertSame(1,DB::table(FixtureAuthorizationService::TABLE)->count()); $this->assertSame('2',(string)DB::table('tx_access_subject')->value('auth_version'));
    }
    public function test_real_sigkill_after_commit_recovers_original_signed_bytes_and_version(): void
    {
        $this->requireMysql(); $dir=$this->root.'/kill'; mkdir($dir); DB::disconnect('authorization_testing'); $pid=pcntl_fork();
        if($pid<0) { throw new RuntimeException('fork'); }
        if($pid===0) { DB::purge('authorization_testing'); DB::listen(function($q) use($dir) {
                if(str_starts_with($q->sql,'insert')&&str_contains($q->sql,FixtureAuthorizationService::TABLE)) {
                    DB::afterCommit(function() use($dir) { touch($dir.'/committed'); sleep(20); });
                }
            }); $this->commit(); exit(2); }
        try { $this->wait($dir.'/committed'); posix_kill($pid,SIGKILL); pcntl_waitpid($pid,$s); $pid=0; DB::purge('authorization_testing');
            $this->assertTrue(pcntl_wifsignaled($s)); $row=DB::table(FixtureAuthorizationService::TABLE)->first(); $this->clock->at+=10;
            $r=$this->commit(); $this->assertSame($row->signed_snapshot,$r['signed_snapshot']); $this->assertSame((int)$row->lease_expires_at,$r['payload']['lease_expires_at']);
            $this->assertSame('2',(string)DB::table('tx_access_subject')->value('auth_version')); $this->assertSame(1,DB::table(FixtureAuthorizationService::TABLE)->count());
        } finally { if($pid>0) { posix_kill($pid,SIGTERM); pcntl_waitpid($pid,$s); } File::deleteDirectory($dir); }
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('raceCases')]
    public function test_real_mutation_first_and_binding_first_arbitrate_current_gates(string $fault,bool $bindingFirst): void
    {
        $this->requireMysql(); $this->clock->at=max(time(),$this->f['at']); $dir=$this->root.'/barrier'; mkdir($dir); $key='fixture_race_'.$fault;
        DB::disconnect('authorization_testing'); $pid=pcntl_fork(); if($pid<0) { throw new RuntimeException('fork'); }
        if($pid===0) { DB::purge('authorization_testing'); $this->wait($dir.'/locked'); touch($dir.'/attempted');
            try { $out=$bindingFirst?($this->mutate($fault,'2')??'MUTATED'):$this->commit($key)['snapshot_ref']; }
            catch(\Throwable $e) { $out=$e instanceof UsageError?$e->errorCode:($e instanceof DomainException?$e->getMessage():'CHILD_FAILED:'.get_class($e)); }
            file_put_contents($dir.'/result',$out); exit(0); }
        try { $seen=false; DB::listen(function($q) use($dir,$bindingFirst,$fault,&$seen) {
                $table=$bindingFirst?FixtureAuthorizationService::TABLE:match($fault) { 'ban'=>'tx_user','reset'=>'tx_usage_period','disable'=>'tx_plugins',
                    'revoke'=>'tx_credential_provider_shadow_revocation','source','generation'=>'tx_node_usage_source' };
                if(!$seen&&str_contains($q->sql,$table)&&(str_starts_with($q->sql,'insert')||str_starts_with($q->sql,'update'))) {
                    $seen=true; touch($dir.'/locked'); $this->wait($dir.'/attempted'); usleep(70000); $this->assertFileDoesNotExist($dir.'/result');
                }
            }); $r=$bindingFirst?$this->commit($key):null; if(!$bindingFirst) { $this->mutate($fault); }
            pcntl_waitpid($pid,$s); $pid=0; $this->assertSame(0,pcntl_wexitstatus($s)); $this->assertTrue($seen); $out=file_get_contents($dir.'/result');
            if($bindingFirst) { $this->assertSame('MUTATED',$out); $this->assertSame('refused',$this->check($r)['local_validation']['state']);
                $this->assertSame($r['signed_snapshot'],$this->commit($key)['signed_snapshot']); }
            else { $expected=match($fault) { 'ban'=>'LEASE_CURRENT_DISABLED','reset'=>'LEASE_PERIOD_INVALID','disable'=>'PROVIDER_DISABLED',
                    'revoke'=>'REVOKED','source'=>'LEASE_SOURCE_UNAVAILABLE','generation'=>'CORE_INSTANCE_RECOVERY_REQUIRED' };
                $this->assertSame($expected,$out); $this->unbound(); }
        } finally { if($pid>0) { posix_kill($pid,SIGTERM); pcntl_waitpid($pid,$s); } File::deleteDirectory($dir); DB::purge('authorization_testing'); }
    }
    public static function raceCases(): array
    {
        $out=[]; foreach(['ban','reset','disable','revoke','source','generation'] as $fault) { $out[$fault.'_mutation_first']=[$fault,false]; $out[$fault.'_binding_first']=[$fault,true]; } return $out;
    }
    private array $trust;
    private function keys(): void
    {
        $key=openssl_pkey_new(['private_key_bits'=>3072,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        if(!$key) { throw new RuntimeException('RSA fixture unavailable'); } openssl_pkey_export($key,$private);
        file_put_contents($this->root.'/signer.pem',$private); chmod($this->root.'/signer.pem',0600);
        file_put_contents($this->root.'/public.pem',openssl_pkey_get_details($key)['key']);
        $this->trust=['version'=>'credential-provider/local-authorization-trust/1','issuer'=>FixtureAuthorizationCodec::ISSUER,
            'audience'=>FixtureAuthorizationCodec::AUDIENCE,'current_kid'=>'fixture_key_1','keys'=>[['kid'=>'fixture_key_1','epoch'=>'1',
                'public_key_path'=>$this->root.'/public.pem','private_key_path'=>$this->root.'/signer.pem','spki_sha256'=>FixtureAuthorizationSigner::spki($key),
                'not_before'=>$this->f['at']-100,'not_after'=>$this->f['at']+500,'revoked'=>false]]];
        $this->writeTrust(); config(['credential_provider_authorization_fixture.trust_file'=>$this->root.'/trust.json']);
    }
    private function writeTrust(): void { file_put_contents($this->root.'/trust.json',FixtureCodec::json($this->trust)); }
    public function revokeKey(): void { $this->trust['keys'][0]['revoked']=true; $this->writeTrust(); }
    public function changeTrustBytes(): void { file_put_contents($this->root.'/trust.json',"\n".FixtureCodec::json($this->trust)); }
    private function service(): FixtureAuthorizationService { return app(FixtureAuthorizationService::class); }
    private function verifier(): FixtureAuthorizationVerifier { return app(FixtureAuthorizationVerifier::class); }
    private function commit(string $key='fixture_authorization',?string $candidate=null): array { return $this->service()->commit($this->f['scope'],$candidate??$this->f['candidate']['candidate_ref'],$key); }
    private function candidate(string $key='fixture_candidate'): array { return app(FixtureLeaseCandidateService::class)->prepare($this->f['scope'],$this->f['issue']['operation_ref'],$key,
        ['expected_subject_version'=>'1','expected_terms_id'=>(string)$this->f['terms'],'source_id'=>(string)$this->f['source']->id,'usage_period_id'=>(string)$this->f['period'],'tariff_revision_id'=>(string)$this->f['tariff']->id]); }
    private function check(array $r): array { return $this->service()->checkCurrent($this->f['scope'],$r['snapshot_ref']); }
    private function unchanged(): array { return array_map(fn($t)=>DB::table($t)->orderBy('id')->get()->toJson(),
        ['tx_user','tx_subscription','tx_subscription_terms','tx_usage_period','tx_credential_provider_shadow_operation',FixtureLeaseCandidateService::TABLE,'tx_auth_snapshot']); }
    private function unbound(): void { $s=DB::table('tx_access_subject')->first(); $this->assertSame('pending',$s->status); $this->assertSame('core_pending_fixture',$s->provider_subject_ref);
        $this->assertSame('1',(string)$s->auth_version); $this->assertSame('1',(string)$s->row_version); $this->assertSame(0,DB::table(FixtureAuthorizationService::TABLE)->count()); }
    private function refused(callable $fn,string $code): void { try { $fn(); $this->fail('Unsafe snapshot accepted'); } catch(DomainException $e) { $this->assertSame($code,$e->getMessage()); } }
    private function b64(string $s): string { return rtrim(strtr(base64_encode($s),'+/','-_'),'='); }
    private function mutate(string $fault,?string $expectedVersion=null): void
    {
        match($fault) { 'ban'=>DB::transaction(fn()=>User::where('id',$this->f['user'])->lockForUpdate()->first()->update(['banned'=>true])),
            'reset'=>$this->resetTerms(),'disable'=>app(PluginManager::class)->disable(FixturePluginBinding::CODE),
            'revoke'=>app(FixtureProviderService::class)->revoke($this->f['scope'],'fixture_race_revoke',['expected_subject_version'=>$expectedVersion??(string)DB::table('tx_access_subject')->value('auth_version'),'reason_code'=>'operator_revoke'],time()+5),
            'source'=>app(UsageRegistry::class)->source($this->f['node'],'lease_installation_0002',time()),'generation'=>$this->advanceGeneration(),
            'subject_version'=>DB::table('tx_access_subject')->increment('auth_version'),'row_version'=>DB::table('tx_access_subject')->increment('row_version'),
            'quota'=>DB::table('tx_usage_period')->update(['billable_bytes'=>1000]),'key'=>$this->revokeKey(),
            'interval'=>DB::table('tx_usage_period')->update(['ends_at'=>$this->f['at']+20]) };
    }
    private function resetTerms(): void { $now=time(); app(SubscriptionTermsShadowService::class)->reset($this->f['user'],(string)$this->f['sub'],'lease_fixture_reset',
        ['kind'=>'reset','policy_version'=>'legacy-reset-explicit/v1','effective_at'=>$now,'expected_subscription_version'=>'1','expected_period_version'=>'1',
            'changes'=>['trigger'=>'manual','verified_legacy_rule'=>'legacy-performReset/manual/v1']],$now); }
    private function advanceGeneration(): void { config(['core_usage_v2.receipt_generation'=>'2']); app(UsageRegistry::class)->advanceGeneration($this->f['node'],'FIXTURE_RECOVERY'); }
    private function clearDatabase(): void { if(DB::connection()->getDatabaseName()!=='txboard_provider_test'||config('database.connections.authorization_testing.username')!=='txboard_provider_fixture') { throw new RuntimeException('DEDICATED_PROVIDER_DATABASE_REQUIRED'); }
        Schema::disableForeignKeyConstraints(); try { foreach(self::TABLES as $t) { Schema::dropIfExists($t); } } finally { Schema::enableForeignKeyConstraints(); } }
    private function requireMysql(): void { if(DB::getDriverName()!=='mysql'||!function_exists('pcntl_fork')) { $this->markTestSkipped('Dedicated MariaDB sessions required'); } }
    private function wait(string $p): void { $end=microtime(true)+10; while(!is_file($p)&&microtime(true)<$end) { usleep(1000); } if(!is_file($p)) { throw new RuntimeException('AUTHORIZATION_BARRIER_TIMEOUT'); } }
    private function parallel(array $actions): array
    {
        $dir=$this->root.'/parallel'; mkdir($dir); $children=[]; DB::disconnect('authorization_testing');
        try { foreach($actions as $i=>$action) { $pid=pcntl_fork(); if($pid<0) { throw new RuntimeException('fork'); }
                if($pid===0) { DB::purge('authorization_testing'); $this->wait($dir.'/go');
                    try { $out=$action(); } catch(DomainException $e) { $out=['error'=>$e->getMessage()]; }
                    catch(\Throwable $e) { file_put_contents($dir.'/'.$i,'CHILD_FAILED:'.get_class($e)); exit(2); }
                    file_put_contents($dir.'/'.$i,json_encode($out)); exit(0); } $children[]=$pid; }
            touch($dir.'/go'); foreach($children as $pid) { pcntl_waitpid($pid,$s); $this->assertSame(0,pcntl_wexitstatus($s)); } $children=[]; DB::purge('authorization_testing');
            return array_map(fn($i)=>json_decode(file_get_contents($dir.'/'.$i),true),array_keys($actions));
        } finally { foreach($children as $pid) { posix_kill($pid,SIGTERM); pcntl_waitpid($pid,$s); } File::deleteDirectory($dir); }
    }
}

/** Trusted fixture wall/monotonic clock; not an input or proof of real Node clocks. */
final class AuthorizationTestClock extends FixtureLeaseClock
{
    public int $at=0; public float $elapsed=0;
    public function wall(): int { return $this->at; }
    public function monotonic(): float { return $this->elapsed; }
}
