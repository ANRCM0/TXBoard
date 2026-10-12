<?php

namespace Tests\Feature\CredentialProviderFixture;

use App\Domains\CredentialProviderFixture\{FixtureCodec, FixtureLeaseCandidateService, FixtureLeaseClock, FixturePluginBinding, FixtureProviderService, FixtureScope};
use App\Domains\UsageV2\{UsageError, UsageRegistry};
use App\Jobs\NodeUserSyncJob;
use App\Models\{Plugin, User};
use App\Services\Plugin\PluginManager;
use App\Services\Subscription\SubscriptionTermsShadowService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, File, Log, Queue, Schema};
use RuntimeException;
use Tests\TestCase;

final class FixtureLeaseCandidateTest extends TestCase
{
    private string $root;
    private array $f;
    private LeaseTestClock $clock;
    private const TABLES = [FixtureLeaseCandidateService::TABLE, FixturePluginBinding::TABLE, 'tx_plugins',
        'tx_credential_provider_shadow_revocation', 'tx_credential_provider_shadow_lifecycle', 'tx_credential_provider_shadow_operation', 'tx_credential_provider_shadow_registration',
        'tx_subscription_terms_shadow_operation', 'tx_node_usage_stat', 'tx_usage_outbox', 'tx_usage_entry', 'tx_usage_batch', 'tx_auth_snapshot', 'tx_tariff_revision', 'tx_node_usage_source',
        'tx_usage_adjustment', 'tx_access_subject', 'tx_legacy_subscription_map', 'tx_legacy_subscription_exception', 'tx_migration_checkpoint',
        'tx_subscription_slot', 'tx_usage_period', 'tx_subscription_terms_transition', 'tx_subscription_terms', 'tx_subscription', 'tx_user', 'tx_server'];
    protected function setUp(): void
    {
        parent::setUp();
        $mysql = getenv('CORE_PROVIDER_LEASE_MYSQL') === '1';
        config(['database.default' => 'lease_testing', 'database.connections.lease_testing' => $mysql ? config('database.connections.mysql')
            : array_merge(config('database.connections.sqlite'), ['database' => ':memory:', 'foreign_key_constraints' => true])]); DB::purge('lease_testing');
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
            '2026_10_12_000012_create_provider_lease_candidate_fixture.php'] as $file) { (require database_path('migrations/'.$file))->up(); }
        $this->root = sys_get_temp_dir().'/txboard-lease-'.bin2hex(random_bytes(8)); mkdir($this->root, 0700);
        config(['credential_provider_lease_fixture.enabled' => true, 'credential_provider_fixture.enabled' => true,
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
        $this->clock = new LeaseTestClock(); $this->clock->at = $at;
        $this->app->instance(FixtureLeaseClock::class, $this->clock);
    }
    protected function tearDown(): void
    {
        if (getenv('CORE_PROVIDER_LEASE_MYSQL') === '1') { $this->clearDatabase(); }
        if (isset($this->root)) { File::deleteDirectory($this->root); } DB::purge('lease_testing'); parent::tearDown();
    }
    public function test_real_receipt_prepares_only_nonsecret_metadata_and_preserves_business_state(): void
    {
        $before = $this->history(); Log::spy(); DB::enableQueryLog(); $c = $this->prepare(); $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertTrue($c['historical_only']); $this->assertFalse($c['admission_granted']); $this->assertNull($c['auth_snapshot_id']);
        $this->assertSame($this->f['at']+60, $c['evidence']['candidate_expires_at']); $this->assertSame($before, $this->history());
        $this->assertSame('pending', DB::table('tx_access_subject')->value('status')); $this->assertSame('core_pending_fixture', $c['evidence']['subject_core_ref']);
        $this->assertNotSame($c['evidence']['subject_core_ref'], $c['evidence']['issue_result']['provider_subject_ref']);
        $this->assertSame(0, DB::table('tx_auth_snapshot')->count());
        foreach ($queries as $q) { $this->assertStringNotContainsString('tx_server', $q['query']); }
        $state = json_decode(file_get_contents($this->root.'/lease_provider/state.json'), true);
        $secret = $state['operations'][$this->f['issue']['operation_ref']]['fixture_secret'];
        $this->assertStringNotContainsString($secret, json_encode($c)); $this->assertStringNotContainsString($secret, DB::table(FixtureLeaseCandidateService::TABLE)->get()->toJson());
        Log::shouldNotHaveReceived('error'); Log::shouldNotHaveReceived('warning');
        $this->assertCandidateForeignKeys();
        try { DB::table('tx_node_usage_source')->where('id', $this->f['source']->id)->delete(); $this->fail('Retained candidate must prevent deleting its source'); }
        catch (QueryException) { $this->assertSame(1, DB::table(FixtureLeaseCandidateService::TABLE)->count()); }
        $this->assertTrue(DB::table('tx_node_usage_source')->where('id', $this->f['source']->id)->exists());
        $this->assertSame('prepared', $this->check($c)['local_validation']['state']);
    }
    public function test_history_replay_survives_user_deletion_disable_rotation_and_expiry_without_extension(): void
    {
        $c = $this->prepare(); app(PluginManager::class)->disable(FixturePluginBinding::CODE);
        DB::table('tx_user')->delete(); DB::table('tx_node_usage_source')->update(['current' => false]); $this->clock->at += 100;
        $again = $this->prepare(); $this->assertSame($c['candidate_ref'], $again['candidate_ref']); $this->assertSame($c['evidence'], $again['evidence']);
        $this->assertSame($again, $this->service()->inspect($this->f['scope'], $c['candidate_ref']));
        $closed = $this->check($c); $this->assertSame('expired', $closed['local_validation']['state']);
        $this->clock->at = $this->f['at']; $this->assertSame('expired', $this->check($c)['local_validation']['state']);
        $this->assertSame(1, DB::table(FixtureLeaseCandidateService::TABLE)->count());
    }
    public function test_terminal_refusal_commits_and_never_reopens_after_ban_is_removed(): void
    {
        $c = $this->prepare(); DB::table('tx_user')->update(['banned' => true]); $r = $this->check($c);
        $this->assertSame('refused', $r['local_validation']['state']); DB::table('tx_user')->update(['banned' => false]);
        $this->assertSame($r['local_validation'], $this->check($c)['local_validation']);
        $this->assertSame('refused', DB::table(FixtureLeaseCandidateService::TABLE)->value('state'));
    }
    public function test_observed_wall_regression_and_return_time_elapsed_close_durably(): void
    {
        $c = $this->prepare(); $this->clock->at += 10; $this->check($c); $this->clock->at -= 5;
        $this->assertSame('LEASE_CLOCK_REGRESSION', $this->check($c)['local_validation']['reason_code']);
        $this->clock->at += 20; $this->assertSame('refused', $this->check($c)['local_validation']['state']);
        $this->clock->elapsed = 61; $other = $this->prepare('fixture_elapsed');
        $this->assertSame('expired', $other['local_validation']['state']);
    }
    public function test_finite_bounds_clip_period_and_tariff_without_mutating_original_receipt(): void
    {
        DB::table('tx_usage_period')->update(['ends_at' => $this->f['at']+40]);
        $this->assertSame($this->f['at']+40, $this->prepare()['evidence']['candidate_expires_at']);
        $row = DB::table('tx_tariff_revision')->first(); $data = ['node_id' => (int) $row->node_id, 'numerator' => (string) $row->numerator,
            'denominator' => (string) $row->denominator, 'effective_from' => (int) $row->effective_from,
            'effective_until' => $this->f['at']+20, 'rounding_revision' => $row->rounding_revision];
        DB::table('tx_tariff_revision')->update(['effective_until' => $data['effective_until'], 'content_hash' => UsageRegistry::hash($data)]);
        $this->assertSame($this->f['at']+20, $this->prepare('fixture_shorter')['evidence']['candidate_expires_at']);
    }
    public function test_strict_input_conflict_scope_and_unknown_issue_fail_closed(): void
    {
        $c = $this->prepare(); $input = $this->input(); $input['source_id'] = '999';
        $this->refused(fn () => $this->prepare('fixture_candidate', $input), 'IDEMPOTENCY_CONFLICT');
        $input = $this->input(); $input['source_id'] = "1\n"; $this->refused(fn () => $this->prepare('fixture_bad', $input), 'PROVIDER_INPUT_INVALID');
        $input = $this->input()+['expires_at' => 999]; $this->refused(fn () => $this->prepare('fixture_bad', $input), 'LEASE_EVIDENCE_INVALID');
        $this->refused(fn () => $this->service()->inspect(new FixtureScope('other_provider', $this->f['scope']->subjectId), $c['candidate_ref']), 'UNAUTHORIZED_SCOPE');
        DB::table('tx_credential_provider_shadow_operation')->update(['status' => 'unknown']);
        $this->refused(fn () => $this->prepare('fixture_unknown'), 'LEASE_ISSUE_INVALID');
    }
    public function test_rehashed_candidate_corruption_never_becomes_retained_authority(): void
    {
        $c=$this->prepare(); $row=DB::table(FixtureLeaseCandidateService::TABLE)->first();
        foreach (['extra','foreign_owner','window','scalar_boundary'] as $fault) {
            $e=json_decode($row->evidence_snapshot,true);
            match($fault) { 'extra'=>$e['private_payload']='forbidden', 'foreign_owner'=>$e['issue_input']['access_subject_id']='999',
                'window'=>$e['candidate_expires_at']+=100, 'scalar_boundary'=>$e['boundary']='bad' };
            if($fault==='foreign_owner') { $e['issue_input_hash']=FixtureCodec::hash($e['issue_input']); }
            DB::table(FixtureLeaseCandidateService::TABLE)->update(['evidence_snapshot'=>FixtureCodec::json($e),'evidence_hash'=>FixtureCodec::hash($e)]);
            try { $this->service()->inspect($this->f['scope'],$c['candidate_ref']); $this->fail('Rehashed candidate damage accepted'); }
            catch(DomainException) { $this->assertTrue(true); }
            DB::table(FixtureLeaseCandidateService::TABLE)->where('id',$row->id)->update((array)$row);
        }
    }
    public function test_rehashed_receipt_extra_fields_foreign_owner_and_wrong_expiry_are_rejected(): void
    {
        $row = DB::table('tx_credential_provider_shadow_operation')->first(); $original = (array) $row;
        foreach (['input_extra', 'foreign_terms', 'result_extra', 'expiry', 'generation', 'error'] as $fault) {
            $input = json_decode($row->input_snapshot, true); $result = json_decode($row->result_snapshot, true); $error = null;
            match ($fault) { 'input_extra' => $input['secret_metadata'] = 'forbidden', 'foreign_terms' => $input['terms_id'] = '999',
                'result_extra' => $result['metadata'] = 'forbidden', 'expiry' => $result['credential_expires_at']++,
                'generation' => $result['credential_generation'] = '2', 'error' => $error = 'UNEXPECTED' };
            DB::table('tx_credential_provider_shadow_operation')->update(['input_snapshot' => FixtureCodec::json($input), 'input_hash' => FixtureCodec::hash($input),
                'result_snapshot' => FixtureCodec::json($result), 'result_hash' => FixtureCodec::hash($result), 'last_error_code' => $error]);
            try { $this->prepare('fixture_damaged'); $this->fail('Rehashed malformed receipt accepted'); } catch (DomainException) { $this->assertTrue(true); }
            DB::table('tx_credential_provider_shadow_operation')->where('id', $row->id)->update($original);
        }
        $this->assertSame(0, DB::table(FixtureLeaseCandidateService::TABLE)->count());
    }
    public function test_all_grants_and_current_subject_period_tariff_gates_are_enforced(): void
    {
        foreach (['issue', 'query', 'revoke'] as $grant) {
            DB::table('tx_credential_provider_shadow_registration')->update(['operation_grants' => json_encode(array_values(array_diff(['issue','query','revoke'],[$grant])))]);
            $this->refused(fn () => $this->prepare(), 'UNSUPPORTED_OPERATION');
        }
        DB::table('tx_credential_provider_shadow_registration')->update(['operation_grants' => '["issue","query","revoke"]']);
        foreach ([['tx_usage_period', 'status', 'closed'], ['tx_usage_period', 'ends_at', null], ['tx_usage_period', 'quota_bytes', 2000],
            ['tx_usage_period', 'billable_bytes', 1000], ['tx_tariff_revision', 'denominator', 0], ['tx_access_subject', 'auth_version', 2]] as [$table,$field,$value]) {
            $old = DB::table($table)->value($field); DB::table($table)->update([$field=>$value]);
            try { $this->prepare(); $this->fail('Current gate accepted invalid evidence'); } catch (DomainException) { $this->assertTrue(true); }
            DB::table($table)->update([$field=>$old]);
        }
        $this->assertSame(0, DB::table(FixtureLeaseCandidateService::TABLE)->count());
    }
    public function test_default_off_production_and_outer_transaction_refuse_without_candidate(): void
    {
        config(['credential_provider_lease_fixture.enabled' => false]); $this->refused(fn () => $this->prepare(), 'CAPABILITY_UNAVAILABLE');
        config(['credential_provider_lease_fixture.enabled' => true]); DB::beginTransaction();
        try { $this->refused(fn () => $this->prepare(), 'PROVIDER_OUTER_TRANSACTION_UNSUPPORTED'); } finally { DB::rollBack(); }
        $this->app->detectEnvironment(fn ()=>'production'); $this->refused(fn () => $this->prepare(), 'CAPABILITY_UNAVAILABLE');
        $this->assertSame(0, DB::table(FixtureLeaseCandidateService::TABLE)->count());
    }
    public function test_trigger_rollback_and_committed_lost_response_replay_preserve_original_identity(): void
    {
        $table = FixtureLeaseCandidateService::TABLE;
        DB::unprepared(DB::getDriverName()==='mysql' ? "CREATE TRIGGER lease_fail BEFORE INSERT ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'"
            : "CREATE TRIGGER lease_fail BEFORE INSERT ON $table BEGIN SELECT RAISE(ABORT,'fixture'); END");
        try { $this->prepare(); $this->fail('Trigger must abort'); } catch (QueryException) { $this->assertSame(0, DB::table($table)->count()); }
        DB::unprepared('DROP TRIGGER lease_fail'); $committed = $this->prepare(); unset($committed); // Simulated application loses returned result after commit.
        $ref = DB::table($table)->value('candidate_ref'); $this->assertSame($ref, $this->prepare()['candidate_ref']);
        $migration = require database_path('migrations/2026_10_12_000012_create_provider_lease_candidate_fixture.php'); $migration->up();
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE '.FixtureLeaseCandidateService::TABLE.' DROP FOREIGN KEY tx_cp_lease_source_id_fk');
            $this->assertCount(8, Schema::getForeignKeys(FixtureLeaseCandidateService::TABLE));
            $migration->up(); // Additive replay repairs the missing FK with its bounded identifier.
        }
        $this->assertCandidateForeignKeys();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Lease candidate history exists; fix forward or restore verified backup.');
        $migration->down();
    }
    public function test_real_separate_session_same_key_has_one_winner(): void
    {
        $this->requireMysql(); $results=$this->parallel([fn ()=>$this->prepare()['candidate_ref'], fn ()=>$this->prepare()['candidate_ref']]);
        $this->assertSame($results[0], $results[1]); $this->assertSame(1, DB::table(FixtureLeaseCandidateService::TABLE)->count());
    }
    public function test_real_sigkill_after_candidate_commit_replays_original_interval(): void
    {
        $this->requireMysql(); $dir=$this->root.'/killed'; mkdir($dir); DB::disconnect('lease_testing');
        $pid=pcntl_fork(); if($pid<0) { throw new RuntimeException('Fork failed'); }
        if($pid===0) {
            DB::purge('lease_testing'); DB::listen(function($q) use($dir) {
                if(str_starts_with($q->sql,'insert')&&str_contains($q->sql,FixtureLeaseCandidateService::TABLE)) {
                    DB::afterCommit(function() use($dir) { touch($dir.'/committed'); sleep(20); });
                }
            }); $this->prepare('fixture_sigkill'); exit(2);
        }
        try {
            $this->wait($dir.'/committed'); posix_kill($pid,SIGKILL); pcntl_waitpid($pid,$status); $pid=0; DB::purge('lease_testing');
            $this->assertTrue(pcntl_wifsignaled($status)); $this->assertSame(SIGKILL,pcntl_wtermsig($status));
            $stored=DB::table(FixtureLeaseCandidateService::TABLE)->first(); $this->clock->at+=10;
            $replay=$this->prepare('fixture_sigkill'); $this->assertSame($stored->candidate_ref,$replay['candidate_ref']);
            $this->assertSame((int)$stored->candidate_expires_at,$replay['evidence']['candidate_expires_at']);
            $this->assertSame(1,DB::table(FixtureLeaseCandidateService::TABLE)->count());
        } finally { if($pid>0) { posix_kill($pid,SIGTERM); pcntl_waitpid($pid,$s); } File::deleteDirectory($dir); }
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('raceCases')]
    public function test_real_mutation_first_and_candidate_first_arbitrate_all_current_gates(string $fault, bool $candidateFirst): void
    {
        $this->requireMysql(); $this->race($fault, $candidateFirst);
    }
    public static function raceCases(): array
    {
        $cases=[];
        foreach (['ban', 'reset', 'disable', 'revoke', 'source', 'generation'] as $fault) {
            $cases[$fault.'_mutation_first']=[$fault,false]; $cases[$fault.'_candidate_first']=[$fault,true];
        }
        return $cases;
    }
    private function race(string $fault, bool $candidateFirst): void
    {
        $this->clock->at=max(time(), $this->f['at']);
        $this->raceFault=$fault; $key='fixture_race_'.$fault.'_'.($candidateFirst?'candidate':'mutation'); $dir=$this->root.'/barrier_'.bin2hex(random_bytes(4)); mkdir($dir);
        $connection=DB::getDefaultConnection(); DB::disconnect($connection); $pid=pcntl_fork(); if ($pid<0) { throw new RuntimeException('fork'); }
        if ($pid===0) {
            DB::purge($connection); $this->wait($dir.'/locked'); touch($dir.'/attempted');
            try { if ($candidateFirst) { $this->mutate($fault); $out='MUTATED'; } else { $out=$this->prepare($key)['candidate_ref']; } }
            catch (\Throwable $e) { $out=$e instanceof UsageError ? $e->errorCode : ($e instanceof DomainException ? $e->getMessage() : 'CHILD_FAILED:'.get_class($e)); }
            file_put_contents($dir.'/result',$out); exit(0);
        }
        try {
            $seen=false;
            DB::listen(function ($q) use ($dir,$candidateFirst,&$seen) {
                $target=$candidateFirst ? FixtureLeaseCandidateService::TABLE : match ($this->raceFault) {
                    'ban'=>'tx_user', 'reset'=>'tx_usage_period', 'disable'=>'tx_plugins', 'revoke'=>'tx_credential_provider_shadow_revocation',
                    'source','generation'=>'tx_node_usage_source' };
                if (!$seen && str_contains($q->sql,$target) && (str_starts_with($q->sql,'insert') || str_starts_with($q->sql,'update'))) {
                    $seen=true; touch($dir.'/locked'); $this->wait($dir.'/attempted'); usleep(70000); $this->assertFileDoesNotExist($dir.'/result');
                }
            });
            $candidate=$candidateFirst ? $this->prepare($key) : null;
            if (!$candidateFirst) { $this->mutate($fault); }
            pcntl_waitpid($pid,$status); $pid=0; $this->assertSame(0,pcntl_wexitstatus($status)); $this->assertTrue($seen);
            $result=file_get_contents($dir.'/result');
            if ($candidateFirst) { $this->assertSame('MUTATED',$result); $this->assertSame('refused',$this->check($candidate)['local_validation']['state']);
                $this->assertSame($candidate['evidence'],$this->prepare($key)['evidence']); }
            else {
                $expected=match ($fault) { 'ban'=>'LEASE_CURRENT_DISABLED', 'reset'=>'LEASE_PERIOD_INVALID', 'disable'=>'PROVIDER_DISABLED',
                    'revoke'=>'REVOKED', 'source'=>'LEASE_SOURCE_UNAVAILABLE', 'generation'=>'CORE_INSTANCE_RECOVERY_REQUIRED' };
                $this->assertSame($expected, $result); $this->assertSame(0, DB::table(FixtureLeaseCandidateService::TABLE)->count());
            }
        } finally { if ($pid>0) { posix_kill($pid,SIGTERM); pcntl_waitpid($pid,$s); } File::deleteDirectory($dir); DB::purge($connection); }
    }
    private string $raceFault='ban';
    private function mutate(string $fault): void
    {
        match ($fault) {
            'ban' => DB::transaction(function () { User::where('id',$this->f['user'])->lockForUpdate()->first()->update(['banned'=>true]); }),
            'reset' => $this->resetTerms(),
            'disable' => app(PluginManager::class)->disable(FixturePluginBinding::CODE),
            'revoke' => app(FixtureProviderService::class)->revoke($this->f['scope'],'fixture_race_revoke',['expected_subject_version'=>'1','reason_code'=>'operator_revoke'],time()+5),
            'source' => app(UsageRegistry::class)->source($this->f['node'],'lease_installation_0002',time()),
            'generation' => $this->advanceGeneration(),
        };
    }
    private function advanceGeneration(): void { config(['core_usage_v2.receipt_generation'=>'2']); app(UsageRegistry::class)->advanceGeneration($this->f['node'],'FIXTURE_RECOVERY'); }
    private function resetTerms(): void
    {
        $now=time(); app(SubscriptionTermsShadowService::class)->reset($this->f['user'],(string)$this->f['sub'],'lease_fixture_reset',
            ['kind'=>'reset','policy_version'=>'legacy-reset-explicit/v1','effective_at'=>$now,'expected_subscription_version'=>'1','expected_period_version'=>'1',
                'changes'=>['trigger'=>'manual','verified_legacy_rule'=>'legacy-performReset/manual/v1']],$now);
    }
    private function parallel(array $actions): array
    {
        $dir=$this->root.'/parallel'; mkdir($dir); $children=[]; DB::disconnect();
        try { foreach ($actions as $i=>$action) { $pid=pcntl_fork(); if ($pid===0) { DB::purge('lease_testing'); $this->wait($dir.'/go');
                    try { file_put_contents($dir.'/'.$i,json_encode($action())); exit(0); } catch (\Throwable) { exit(2); } } $children[]=$pid; }
            touch($dir.'/go'); foreach($children as $pid) { pcntl_waitpid($pid,$s); $this->assertSame(0,pcntl_wexitstatus($s)); } $children=[]; DB::purge('lease_testing');
            return array_map(fn($i)=>json_decode(file_get_contents($dir.'/'.$i),true),array_keys($actions));
        } finally { foreach($children as $pid) { posix_kill($pid,SIGTERM); pcntl_waitpid($pid,$s); } File::deleteDirectory($dir); }
    }
    private function wait(string $p): void { $end=microtime(true)+10; while(!is_file($p)&&microtime(true)<$end) { usleep(1000); } if(!is_file($p)) { throw new RuntimeException('LEASE_BARRIER_TIMEOUT'); } }
    private function requireMysql(): void { if(DB::getDriverName()!=='mysql'||!function_exists('pcntl_fork')) { $this->markTestSkipped('Dedicated MariaDB sessions required'); } }
    private function assertCandidateForeignKeys(): void
    {
        $expected = ['binding_id'=>'tx_credential_provider_shadow_plugin_binding', 'registration_id'=>'tx_credential_provider_shadow_registration',
            'issue_operation_id'=>'tx_credential_provider_shadow_operation', 'source_id'=>'tx_node_usage_source', 'access_subject_id'=>'tx_access_subject',
            'subscription_id'=>'tx_subscription', 'terms_id'=>'tx_subscription_terms', 'usage_period_id'=>'tx_usage_period', 'tariff_revision_id'=>'tx_tariff_revision'];
        $keys = Schema::getForeignKeys(FixtureLeaseCandidateService::TABLE); $this->assertCount(count($expected), $keys);
        foreach ($keys as $key) {
            $this->assertCount(1, $key['columns']); $column=$key['columns'][0]; $this->assertArrayHasKey($column, $expected);
            $this->assertSame($expected[$column], $key['foreign_table']); $this->assertSame(['id'], $key['foreign_columns']);
            $this->assertContains($key['on_delete'], ['restrict','no action']);
            if (DB::getDriverName() === 'mysql') { $this->assertSame('tx_cp_lease_'.$column.'_fk', $key['name']); $this->assertLessThanOrEqual(64, strlen($key['name'])); }
            unset($expected[$column]);
        }
        $this->assertSame([], $expected);
    }
    private function clearDatabase(): void { if(DB::connection()->getDatabaseName()!=='txboard_provider_test'||config('database.connections.lease_testing.username')!=='txboard_provider_fixture') { throw new RuntimeException('DEDICATED_PROVIDER_DATABASE_REQUIRED'); }
        Schema::disableForeignKeyConstraints(); try { foreach(self::TABLES as $t) { Schema::dropIfExists($t); } } finally { Schema::enableForeignKeyConstraints(); } }
    private function input(): array { return ['expected_subject_version'=>'1','expected_terms_id'=>(string)$this->f['terms'],'source_id'=>(string)$this->f['source']->id,
        'usage_period_id'=>(string)$this->f['period'],'tariff_revision_id'=>(string)$this->f['tariff']->id]; }
    private function prepare(string $key='fixture_candidate',?array $input=null): array { return $this->service()->prepare($this->f['scope'],$this->f['issue']['operation_ref'],$key,$input??$this->input()); }
    private function service(): FixtureLeaseCandidateService { return app(FixtureLeaseCandidateService::class); }
    private function check(array $c): array { return $this->service()->checkCurrent($this->f['scope'],$c['candidate_ref']); }
    private function history(): array { return array_map(fn($t)=>DB::table($t)->orderBy('id')->get()->toJson(),['tx_user','tx_subscription','tx_access_subject','tx_usage_period','tx_auth_snapshot']); }
    private function refused(callable $action,string $code): void { try { $action(); $this->fail('Unsafe candidate accepted'); } catch(DomainException $e) { $this->assertSame($code,$e->getMessage()); } }
}

final class LeaseTestClock extends FixtureLeaseClock
{
    public int $at=0; public float $elapsed=0; private bool $flip=false;
    public function wall(): int { return $this->at; }
    public function monotonic(): float { $this->flip=!$this->flip; return $this->flip ? 0 : $this->elapsed; }
}
