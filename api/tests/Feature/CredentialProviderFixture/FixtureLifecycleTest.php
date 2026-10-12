<?php

namespace Tests\Feature\CredentialProviderFixture;

use App\Domains\CredentialProviderFixture\FixtureCodec;
use App\Domains\CredentialProviderFixture\FixtureLifecycleService;
use App\Domains\CredentialProviderFixture\FixtureProcess;
use App\Domains\CredentialProviderFixture\FixtureProviderService;
use App\Domains\CredentialProviderFixture\FixtureScope;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class FixtureLifecycleTest extends TestCase
{
    private string $privateRoot;
    private const TABLES = ['tx_credential_provider_shadow_revocation', 'tx_credential_provider_shadow_lifecycle',
        'tx_credential_provider_shadow_operation', 'tx_credential_provider_shadow_registration', 'tx_usage_adjustment',
        'tx_access_subject', 'tx_legacy_subscription_map', 'tx_legacy_subscription_exception', 'tx_migration_checkpoint',
        'tx_subscription_slot', 'tx_usage_period', 'tx_subscription_terms_transition', 'tx_subscription_terms', 'tx_subscription'];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CORE_PROVIDER_TEST_MYSQL') === '1') {
            config(['database.default' => 'provider_lifecycle_mysql_testing',
                'database.connections.provider_lifecycle_mysql_testing' => config('database.connections.mysql')]);
            DB::purge('provider_lifecycle_mysql_testing'); $this->clearMysqlFixtures();
        } else {
            config(['database.default' => 'provider_lifecycle_testing', 'database.connections.provider_lifecycle_testing' =>
                array_merge(config('database.connections.sqlite'), ['database' => ':memory:', 'foreign_key_constraints' => true])]);
            DB::purge('provider_lifecycle_testing');
        }
        $this->privateRoot = sys_get_temp_dir().'/txboard-provider-lifecycle-'.bin2hex(random_bytes(8)); mkdir($this->privateRoot, 0700);
        config(['core_shadow.isolated_copy' => true, 'credential_provider_fixture.enabled' => true,
            'credential_provider_fixture.private_root' => $this->privateRoot,
            'credential_provider_fixture.wall_timeout_seconds' => 1.0, 'credential_provider_fixture.test_fault' => null]);
        (require database_path('migrations/2026_10_12_000001_create_subscription_shadow_tables.php'))->up();
        (require database_path('migrations/2026_10_12_000007_create_credential_provider_shadow_tables.php'))->up();
        $this->migration()->up(); $this->register();
    }

    protected function tearDown(): void
    {
        if (getenv('CORE_PROVIDER_TEST_MYSQL') === '1') { $this->clearMysqlFixtures(); DB::purge('provider_lifecycle_mysql_testing'); }
        if (isset($this->privateRoot) && is_dir($this->privateRoot)) {
            foreach (glob($this->privateRoot.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
                foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); } rmdir($directory);
            }
            rmdir($this->privateRoot);
        }
        DB::purge('provider_lifecycle_testing'); parent::tearDown();
    }

    public function test_revoke_removes_private_secret_and_retains_issue_history_without_mutating_live_subject(): void
    {
        $scope = $this->subject(); $issued = $this->issue($scope); $before = $this->subjectRow($scope);
        $secret = $this->privateState()['operations'][$issued['operation_ref']]['fixture_secret']; Log::spy();
        $revoked = $this->revoke($scope);
        $this->assertSame('succeeded', $revoked['status']); $this->assertSame('not_implemented', $revoked['node_convergence']);
        $this->assertSame('1', $revoked['result']['removed_secret_count']);
        $this->assertSame('1', $revoked['result']['accepted_revocation_fact_version']);
        $state = $this->privateState(); $this->assertCount(2, $state['operations']); $this->assertCount(1, $state['tombstones']);
        $this->assertArrayNotHasKey('fixture_secret', $state['operations'][$issued['operation_ref']]);
        $this->assertEquals($issued['result'], $state['operations'][$issued['operation_ref']]['receipt']);
        $this->assertSame($issued, $this->provider()->query($scope, $issued['operation_ref'], time() + 5));
        $this->assertSame($revoked, $this->provider()->query($scope, $revoked['operation_ref'], time() + 5));
        $this->assertEquals($before, $this->subjectRow($scope));
        $core = FixtureCodec::json(['tables' => $this->snapshot(), 'receipt' => $revoked]);
        $this->assertStringNotContainsString($secret, $core); $this->assertStringNotContainsString($secret, FixtureCodec::json($state));
        foreach (['debug', 'info', 'warning', 'error', 'log'] as $method) { Log::shouldNotHaveReceived($method); }
        $this->assertRejected(fn () => $this->issue($scope, 'fixture_reissue'), 'REVOKED');
    }

    public function test_revoke_before_issue_records_permanent_tombstone_and_allocates_versions_in_registration_domain(): void
    {
        $first = $this->subject(); $second = $this->subject();
        $one = $this->revoke($first, 'fixture_first'); $two = $this->revoke($second, 'fixture_second');
        $this->assertSame('0', $one['result']['removed_secret_count']); $this->assertSame('0', $one['result']['affected_subject_count']);
        $this->assertSame('1', $one['result']['accepted_revocation_fact_version']); $this->assertSame('2', $two['result']['accepted_revocation_fact_version']);
        $this->assertRejected(fn () => $this->issue($first), 'REVOKED');
        $this->assertRejected(fn () => $this->revoke($first, 'fixture_new_key'), 'REVOKED');
        $this->assertSame(0, DB::table('tx_credential_provider_shadow_operation')->where('operation', 'issue')->count());
        $this->assertCount(2, $this->privateState()['tombstones']);
    }

    public function test_same_revoke_key_replays_original_fact_and_changed_input_or_version_is_rejected(): void
    {
        $scope = $this->subject(); $first = $this->revoke($scope); $before = $this->snapshot(); $private = $this->privateState();
        $this->assertSame($first, $this->revoke($scope)); $this->assertSame($before, $this->snapshot());
        $this->assertSame($private, $this->privateState());
        $this->assertRejected(fn () => $this->provider()->revoke($scope, 'fixture_revoke',
            ['expected_subject_version' => '1', 'reason_code' => 'provider_disable'], time() + 5), 'IDEMPOTENCY_CONFLICT');
        $this->assertRejected(fn () => $this->provider()->revoke($this->subject(), 'fixture_wrong_version',
            ['expected_subject_version' => '2', 'reason_code' => 'operator_revoke'], time() + 5), 'VERSION_CONFLICT');
        $this->assertRejected(fn () => $this->provider()->revoke($this->subject(), 'fixture_self_version',
            ['expected_subject_version' => '1', 'reason_code' => 'operator_revoke', 'revocation_fact_version' => '999'], time() + 5), 'PROVIDER_INPUT_INVALID');
        $this->assertRejected(fn () => $this->provider()->revoke($this->subject(), 'fixture_bad_reason',
            ['expected_subject_version' => '1', 'reason_code' => 'arbitrary'], time() + 5), 'PROVIDER_INPUT_INVALID');
    }

    public function test_core_fact_survives_missing_runtime_and_unknown_revoke_is_only_queried_not_reexecuted(): void
    {
        $scope = $this->subject(); $issued = $this->issue($scope); config(['credential_provider_fixture.private_root' => null]);
        $unknown = $this->revoke($scope); $this->assertSame('unknown', $unknown['status']);
        $this->assertSame(1, DB::table('tx_credential_provider_shadow_revocation')->count());
        $this->assertRejected(fn () => $this->issue($scope, 'fixture_blocked'), 'REVOKED');
        config(['credential_provider_fixture.private_root' => $this->privateRoot]);
        $before = $this->privateState(); $query = $this->revoke($scope);
        $this->assertSame('unknown', $query['status']); $this->assertSame('OPERATION_UNKNOWN', $query['error_code']);
        $this->assertSame($unknown['operation_ref'], $query['operation_ref']); $this->assertSame($before, $this->privateState());
        $this->assertArrayHasKey('fixture_secret', $before['operations'][$issued['operation_ref']]);
        $this->lifecycle()->disable('fixture_provider', 'fixture_disable');
        $page = $this->lifecycle()->cleanup('fixture_provider', 'fixture_disable', 1, time() + 5);
        $this->assertFalse($page['fixture_cleanup_complete']); $this->assertSame('0', $page['cursor']);
        $this->assertSame('CLEANUP_REVOKE_UNKNOWN', $page['error_code']);
    }

    public function test_response_loss_and_timeout_after_private_cleanup_recover_only_the_original_revoke_receipt(): void
    {
        foreach (['after_commit_drop', 'after_commit_sleep'] as $fault) {
            $scope = $this->subject(); $issued = $this->issue($scope, 'fixture_issue_'.$fault);
            config(['credential_provider_fixture.test_fault' => $fault]);
            $unknown = $this->revoke($scope, 'fixture_revoke_'.$fault); $this->assertSame('unknown', $unknown['status']);
            $state = $this->privateState(); $this->assertArrayNotHasKey('fixture_secret', $state['operations'][$issued['operation_ref']]);
            config(['credential_provider_fixture.test_fault' => null]);
            $recovered = $this->revoke($scope, 'fixture_revoke_'.$fault); $this->assertSame('succeeded', $recovered['status']);
            $this->assertSame($unknown['operation_ref'], $recovered['operation_ref']); $this->assertSame($state, $this->privateState());
            $this->assertEquals($state['operations'][$unknown['operation_ref']]['receipt'], $recovered['result']);
        }
    }

    public function test_core_finalization_failure_does_not_undo_private_cleanup_or_the_committed_core_fact(): void
    {
        $scope = $this->subject(); $issued = $this->issue($scope);
        DB::unprepared(DB::getDriverName() === 'mysql'
            ? "CREATE TRIGGER cp_revoke_finalize BEFORE UPDATE ON tx_credential_provider_shadow_operation FOR EACH ROW BEGIN IF NEW.operation = 'revoke' AND NEW.status = 'succeeded' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected failure'; END IF; END"
            : "CREATE TRIGGER cp_revoke_finalize BEFORE UPDATE ON tx_credential_provider_shadow_operation WHEN NEW.operation = 'revoke' AND NEW.status = 'succeeded' BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
        $unknown = $this->revoke($scope); $this->assertSame('unknown', $unknown['status']);
        $this->assertSame('CORE_FINALIZATION_UNKNOWN', $unknown['error_code']);
        $state = $this->privateState(); $this->assertArrayNotHasKey('fixture_secret', $state['operations'][$issued['operation_ref']]);
        $this->assertSame(1, DB::table('tx_credential_provider_shadow_revocation')->count());
        DB::unprepared('DROP TRIGGER cp_revoke_finalize');
        $recovered = $this->provider()->query($scope, $unknown['operation_ref'], time() + 5);
        $this->assertSame('succeeded', $recovered['status']); $this->assertSame($state, $this->privateState());
    }

    public function test_lost_private_issuance_history_cannot_be_claimed_as_an_empty_successful_cleanup(): void
    {
        $scope = $this->subject(); $issued = $this->issue($scope);
        $path = $this->privateRoot.'/fixture_provider/state.json'; $saved = file_get_contents($path); unlink($path);
        $unknown = $this->revoke($scope); $this->assertSame('unknown', $unknown['status']); $this->assertSame('OPERATION_UNKNOWN', $unknown['error_code']);
        $this->assertFileDoesNotExist($path);
        file_put_contents($path, $saved);
        $replay = $this->revoke($scope); $this->assertSame('unknown', $replay['status']); $this->assertSame('OPERATION_UNKNOWN', $replay['error_code']);
        $this->assertArrayHasKey('fixture_secret', $this->privateState()['operations'][$issued['operation_ref']]);
        $this->lifecycle()->disable('fixture_provider', 'fixture_disable');
        $blocked = $this->lifecycle()->cleanup('fixture_provider', 'fixture_disable', 1, time() + 5);
        $this->assertFalse($blocked['fixture_cleanup_complete']); $this->assertSame('0', $blocked['cursor']);
    }

    public function test_recording_fact_failure_rolls_back_operation_and_version_without_calling_the_runtime(): void
    {
        $scope = $this->subject();
        DB::unprepared(DB::getDriverName() === 'mysql'
            ? "CREATE TRIGGER cp_fact_fail BEFORE INSERT ON tx_credential_provider_shadow_revocation FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected failure'"
            : "CREATE TRIGGER cp_fact_fail BEFORE INSERT ON tx_credential_provider_shadow_revocation BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
        try { $this->revoke($scope); $this->fail('Fact failure escaped transaction'); } catch (QueryException) { $this->assertTrue(true); }
        $this->assertSame(0, DB::table('tx_credential_provider_shadow_operation')->count());
        $this->assertSame(0, DB::table('tx_credential_provider_shadow_lifecycle')->count());
        $this->assertSame([], glob($this->privateRoot.'/*') ?: []);
    }

    public function test_core_fact_recorded_after_issue_recording_prevents_the_later_issue_dispatch(): void
    {
        $scope = $this->subject(); $scheduled = false;
        DB::connection()->listen(function ($query) use ($scope, &$scheduled) {
            if (!$scheduled && str_starts_with($query->sql, 'insert into') && str_contains($query->sql, 'tx_credential_provider_shadow_operation')) {
                $scheduled = true; DB::afterCommit(fn () => $this->revoke($scope));
            }
        });
        $this->assertRejected(fn () => $this->issue($scope), 'REVOKED');
        $this->assertTrue($scheduled); $this->assertSame('recorded', DB::table('tx_credential_provider_shadow_operation')->where('operation', 'issue')->value('status'));
        $this->assertCount(1, $this->privateState()['operations']); $this->assertCount(1, $this->privateState()['tombstones']);
    }

    public function test_disable_after_issue_recording_fences_dispatch_and_retains_unknown_history(): void
    {
        $scope = $this->subject(); $scheduled = false;
        DB::connection()->listen(function ($query) use (&$scheduled) {
            if (!$scheduled && str_starts_with($query->sql, 'insert into') && str_contains($query->sql, 'tx_credential_provider_shadow_operation')) {
                $scheduled = true; DB::afterCommit(fn () => $this->lifecycle()->disable('fixture_provider', 'fixture_disable'));
            }
        });
        $this->assertRejected(fn () => $this->issue($scope), 'PROVIDER_DISABLED');
        $this->assertSame([], glob($this->privateRoot.'/*') ?: []);
        $page = $this->lifecycle()->cleanup('fixture_provider', 'fixture_disable', 1, time() + 5);
        $this->assertSame('disabling', $page['state']); $this->assertSame('0', $page['cursor']);
        $this->assertSame('CLEANUP_ISSUE_UNKNOWN', $page['error_code']); $this->assertFalse($page['fixture_cleanup_complete']);
        $this->assertFileDoesNotExist($this->privateRoot.'/fixture_provider/state.json');
    }

    public function test_disable_and_cleanup_use_bounded_prefixes_and_keep_all_operation_history_and_tombstones(): void
    {
        $subjects = []; $beforeSubjects = [];
        for ($i = 0; $i < 3; $i++) { $scope = $this->subject(); $subjects[] = $scope; $beforeSubjects[] = $this->subjectRow($scope); $this->issue($scope, 'fixture_issue_'.$i); }
        $start = $this->lifecycle()->disable('fixture_provider', 'fixture_disable');
        $this->assertSame('disabling', $start['state']); $this->assertSame('3', $start['high_water_id']); $this->assertSame('0', $start['cursor']);
        $this->assertRejected(fn () => $this->issue($this->subject(), 'fixture_disabled'), 'PROVIDER_DISABLED');
        for ($i = 1; $i <= 3; $i++) {
            $page = $this->lifecycle()->cleanup('fixture_provider', 'fixture_disable', 1, time() + 5);
            $this->assertSame('1', $page['processed_count']); $this->assertSame((string) $i, $page['cursor']);
            $this->assertSame((string) (3 - $i), $page['remaining_count']); $this->assertSame($i < 3, $page['has_more']);
            $this->assertSame($i === 3, $page['fixture_cleanup_complete']); $this->assertSame('not_implemented', $page['node_convergence']);
        }
        $this->assertSame('disabled', $page['state']); $this->assertCount(6, $this->privateState()['operations']);
        $this->assertCount(3, $this->privateState()['tombstones']);
        foreach ($this->privateState()['operations'] as $record) { $this->assertArrayNotHasKey('fixture_secret', $record); }
        foreach ($subjects as $i => $scope) { $this->assertEquals($beforeSubjects[$i], $this->subjectRow($scope)); }
        $snapshot = $this->snapshot(); $private = $this->privateState();
        $this->lifecycle()->cleanup('fixture_provider', 'fixture_disable', 1, time() + 5);
        $this->assertSame($snapshot, $this->snapshot()); $this->assertSame($private, $this->privateState());
    }

    public function test_empty_disable_completes_only_after_cleanup_and_replay_keeps_original_identity(): void
    {
        $one = $this->lifecycle()->disable('fixture_provider', 'fixture_empty');
        $this->assertFalse($one['fixture_cleanup_complete']); $this->assertSame('0', $one['high_water_id']);
        $this->assertSame($one, $this->lifecycle()->disable('fixture_provider', 'fixture_empty'));
        $done = $this->lifecycle()->cleanup('fixture_provider', 'fixture_empty', 32, time() + 5);
        $this->assertTrue($done['fixture_cleanup_complete']); $this->assertSame('disabled', $done['state']);
        $this->assertSame($done, $this->lifecycle()->status('fixture_provider', 'fixture_empty'));
        $this->assertRejected(fn () => $this->lifecycle()->disable('fixture_provider', 'fixture_different'), 'IDEMPOTENCY_CONFLICT');
        $this->assertSame([], glob($this->privateRoot.'/*') ?: []);
    }

    public function test_disabled_query_and_revoke_recovery_still_require_current_grants_epoch_and_scope(): void
    {
        $scope = $this->subject(); $issued = $this->issue($scope); $this->lifecycle()->disable('fixture_provider', 'fixture_disable');
        $revoked = $this->revoke($scope); $this->assertSame('succeeded', $revoked['status']);
        $this->assertSame($issued, $this->provider()->query($scope, $issued['operation_ref'], time() + 5));
        $this->assertRejected(fn () => $this->provider()->query($this->subject(), $revoked['operation_ref'], time() + 5), 'UNAUTHORIZED_SCOPE');
        DB::table('tx_credential_provider_shadow_registration')->update(['operation_grants' => '["issue","revoke"]']);
        $this->assertRejected(fn () => $this->provider()->query($scope, $revoked['operation_ref'], time() + 5), 'UNSUPPORTED_OPERATION');
        $this->assertRejected(fn () => $this->lifecycle()->cleanup('fixture_provider', 'fixture_disable', 1, time() + 5), 'UNSUPPORTED_OPERATION');
    }

    public function test_revocation_grants_changed_after_recording_cannot_remove_secret_and_replay_only_queries(): void
    {
        $scope = $this->subject(); $issued = $this->issue($scope); $changed = false;
        DB::connection()->listen(function ($query) use (&$changed) {
            if (!$changed && str_starts_with($query->sql, 'insert into') && str_contains($query->sql, 'tx_credential_provider_shadow_revocation')) {
                $changed = true; DB::table('tx_credential_provider_shadow_registration')->update(['operation_grants' => '["issue","query"]']);
            }
        });
        $this->assertRejected(fn () => $this->revoke($scope), 'UNSUPPORTED_OPERATION');
        $ref = DB::table('tx_credential_provider_shadow_operation')->where('operation', 'revoke')->value('operation_ref');
        $this->assertSame(1, DB::table('tx_credential_provider_shadow_revocation')->count());
        $this->assertArrayHasKey('fixture_secret', $this->privateState()['operations'][$issued['operation_ref']]);
        $query = $this->provider()->query($scope, $ref, time() + 5); $this->assertSame('unknown', $query['status']);
        $this->assertSame('OPERATION_UNKNOWN', $query['error_code']);
    }

    public function test_fact_and_lifecycle_hash_damage_or_source_growth_blocks_completion_without_repair(): void
    {
        $scope = $this->subject(); $this->revoke($scope);
        DB::table('tx_credential_provider_shadow_revocation')->update(['fact_version' => 10]);
        $ref = DB::table('tx_credential_provider_shadow_operation')->where('operation', 'revoke')->value('operation_ref');
        $this->assertRejected(fn () => $this->provider()->query($scope, $ref, time() + 5), 'PROVIDER_OPERATION_DAMAGED');
        $this->lifecycle()->disable('fixture_provider', 'fixture_disable');
        DB::table('tx_credential_provider_shadow_lifecycle')->update(['disable_hash' => str_repeat('0', 64)]);
        $this->assertRejected(fn () => $this->lifecycle()->status('fixture_provider', 'fixture_disable'), 'PROVIDER_LIFECYCLE_DAMAGED');
    }

    public function test_issue_above_frozen_high_water_is_detected_even_on_an_empty_cleanup_page(): void
    {
        $scope = $this->subject(); $issued = $this->issue($scope); $this->lifecycle()->disable('fixture_provider', 'fixture_disable');
        $copy = (array) DB::table('tx_credential_provider_shadow_operation')->where('operation_ref', $issued['operation_ref'])->first();
        unset($copy['id']); $copy['access_subject_id'] = $this->subject()->subjectId; $copy['operation_ref'] = 'op_'.bin2hex(random_bytes(16)); $copy['idempotency_key'] = 'fixture_bad_growth';
        DB::table('tx_credential_provider_shadow_operation')->insert($copy);
        $page = $this->lifecycle()->cleanup('fixture_provider', 'fixture_disable', 1, time() + 5);
        $this->assertFalse($page['fixture_cleanup_complete']); $this->assertSame('CLEANUP_SOURCE_GROWTH', $page['error_code']);
        $empty = $this->lifecycle()->cleanup('fixture_provider', 'fixture_disable', 1, time() + 5);
        $this->assertSame('0', $empty['processed_count']); $this->assertFalse($empty['fixture_cleanup_complete']);
        $this->assertTrue($empty['has_more']);
    }

    public function test_new_lifecycle_guard_and_batch_limits_do_not_start_a_process(): void
    {
        $this->assertRejected(fn () => $this->lifecycle()->cleanup('fixture_provider', 'fixture_disable', 1, time() + 5), 'PROVIDER_DISABLE_REQUIRED');
        $this->lifecycle()->disable('fixture_provider', 'fixture_disable');
        foreach ([0, 33] as $limit) { $this->assertRejected(fn () => $this->lifecycle()->cleanup('fixture_provider', 'fixture_disable', $limit, time() + 5), 'PROVIDER_BATCH_LIMIT'); }
        config(['core_shadow.isolated_copy' => false]);
        $this->assertRejected(fn () => $this->lifecycle()->disable('fixture_provider', 'fixture_disabled'), 'CAPABILITY_UNAVAILABLE');
        config(['core_shadow.isolated_copy' => true]); DB::beginTransaction();
        try { $this->assertRejected(fn () => $this->lifecycle()->status('fixture_provider', 'fixture_disable'), 'PROVIDER_OUTER_TRANSACTION_UNSUPPORTED'); }
        finally { DB::rollBack(); }
        $this->assertSame([], glob($this->privateRoot.'/*') ?: []);
    }

    public function test_cleanup_recovers_private_committed_unknown_issue_and_revoke_without_new_private_operations(): void
    {
        $scope = $this->subject(); config(['credential_provider_fixture.test_fault' => 'after_commit_drop']);
        $unknown = $this->issue($scope); $this->assertSame('unknown', $unknown['status']);
        $this->lifecycle()->disable('fixture_provider', 'fixture_disable');
        // Query explains the original issue; the newly recorded revoke commits privately and loses its reply.
        $blocked = $this->lifecycle()->cleanup('fixture_provider', 'fixture_disable', 1, time() + 5);
        $this->assertFalse($blocked['fixture_cleanup_complete']); $this->assertSame('0', $blocked['cursor']);
        $this->assertSame('CLEANUP_REVOKE_UNKNOWN', $blocked['error_code']); $this->assertCount(2, $this->privateState()['operations']);
        $this->assertArrayNotHasKey('fixture_secret', $this->privateState()['operations'][$unknown['operation_ref']]);
        $before = $this->privateState(); config(['credential_provider_fixture.test_fault' => null]);
        $done = $this->lifecycle()->cleanup('fixture_provider', 'fixture_disable', 1, time() + 5);
        $this->assertTrue($done['fixture_cleanup_complete']); $this->assertSame('1', $done['cursor']);
        $this->assertSame($before, $this->privateState());
        $this->assertSame(2, DB::table('tx_credential_provider_shadow_operation')->count());
    }

    public function test_private_tombstone_refuses_lower_fact_version_and_new_issue_even_without_core_fact(): void
    {
        $scope = $this->subject(); $this->revoke($this->subject(), 'fixture_prior'); $this->revoke($scope);
        $row = DB::table('tx_credential_provider_shadow_operation')->where('access_subject_id', $scope->subjectId)->first();
        $input = json_decode($row->input_snapshot, true, flags: JSON_THROW_ON_ERROR); $input['revocation_fact_version'] = '1';
        $request = ['contract_version' => FixtureCodec::VERSION, 'operation' => 'revoke', 'request_id' => 'rq_'.bin2hex(random_bytes(16)),
            'operation_id' => 'op_'.bin2hex(random_bytes(16)), 'idempotency_key' => 'fixture_low', 'provider_id' => 'fixture_provider',
            'provider_instance_epoch' => '1', 'deadline_at' => time() + 5, 'input_hash' => FixtureCodec::hash($input), 'input' => $input];
        $before = $this->privateState(); $reply = (new FixtureProcess())->call($request);
        $this->assertSame('rejected', $reply['status']); $this->assertSame('REVOCATION_VERSION_CONFLICT', $reply['error_code']);
        $this->assertSame($before, $this->privateState());
        // SQL removal is test tampering only; independent private tombstone still refuses resurrection.
        DB::table('tx_credential_provider_shadow_revocation')->where('access_subject_id', $scope->subjectId)->delete();
        $reissued = $this->issue($scope); $this->assertSame('rejected', $reissued['status']); $this->assertSame('REVOKED', $reissued['error_code']);
        $this->assertSame($before, $this->privateState());
    }

    public function test_completed_label_cannot_hide_later_damaged_operation_receipt_or_missing_fact(): void
    {
        $scope = $this->subject(); $this->issue($scope); $this->lifecycle()->disable('fixture_provider', 'fixture_disable');
        $done = $this->lifecycle()->cleanup('fixture_provider', 'fixture_disable', 1, time() + 5); $this->assertTrue($done['fixture_cleanup_complete']);
        $revoke = DB::table('tx_credential_provider_shadow_operation')->where('operation', 'revoke')->first();
        DB::table('tx_credential_provider_shadow_operation')->where('id', $revoke->id)->update(['result_hash' => str_repeat('0', 64)]);
        $status = $this->lifecycle()->status('fixture_provider', 'fixture_disable');
        $this->assertSame('disabled', $status['state']); $this->assertFalse($status['fixture_cleanup_complete']);
        $this->assertSame('PROVIDER_OPERATION_DAMAGED', $status['error_code']);
        $this->assertSame(str_repeat('0', 64), DB::table('tx_credential_provider_shadow_operation')->where('id', $revoke->id)->value('result_hash'));
        DB::table('tx_credential_provider_shadow_operation')->where('id', $revoke->id)->update(['result_hash' => $revoke->result_hash]);
        DB::table('tx_credential_provider_shadow_revocation')->delete();
        $this->assertFalse($this->lifecycle()->status('fixture_provider', 'fixture_disable')['fixture_cleanup_complete']);
    }

    public function test_completion_history_has_a_hard_guard_before_loading_unbounded_rows(): void
    {
        $scope = $this->subject(); $this->revoke($scope); $this->lifecycle()->disable('fixture_provider', 'fixture_disable');
        $template = (array) DB::table('tx_credential_provider_shadow_operation')->first(); unset($template['id']);
        Schema::disableForeignKeyConstraints();
        try {
            for ($i = 0; $i < 512; $i++) {
                DB::table('tx_credential_provider_shadow_operation')->insert(array_merge($template, [
                    'access_subject_id' => $i + 10000, 'operation_ref' => 'op_'.bin2hex(random_bytes(16)), 'idempotency_key' => 'fixture_fake_'.$i]));
            }
        } finally { Schema::enableForeignKeyConstraints(); }
        DB::connection()->enableQueryLog();
        $blocked = $this->lifecycle()->cleanup('fixture_provider', 'fixture_disable', 1, time() + 5);
        $queries = DB::connection()->getQueryLog(); DB::connection()->disableQueryLog();
        $this->assertFalse($blocked['fixture_cleanup_complete']); $this->assertSame('CLEANUP_HISTORY_LIMIT', $blocked['error_code']);
        $this->assertTrue((bool) array_filter($queries, fn ($q) => str_contains($q['query'], 'limit 513')));
    }

    public function test_script_digest_is_never_upgraded_silently_and_core_disable_still_fences_unavailable_runtime(): void
    {
        DB::table('tx_credential_provider_shadow_registration')->update(['script_digest' => str_repeat('0', 64)]);
        $this->assertRejected(fn () => $this->register(), 'PROVIDER_REGISTRATION_CONFLICT');
        $this->assertRejected(fn () => $this->issue($this->subject()), 'RUNTIME_UNAVAILABLE');
        $disabled = $this->lifecycle()->disable('fixture_provider', 'fixture_disable'); $this->assertSame('disabling', $disabled['state']);
        $this->assertFalse((bool) DB::table('tx_credential_provider_shadow_registration')->value('enabled'));
        $this->assertSame(str_repeat('0', 64), DB::table('tx_credential_provider_shadow_registration')->value('script_digest'));
    }

    public function test_version_overflow_and_nonempty_schema_rollback_fail_closed_with_additive_replay(): void
    {
        $scope = $this->subject(); $this->revoke($scope); $before = $this->snapshot(); $this->migration()->up();
        $this->assertSame($before, $this->snapshot());
        $this->assertRejected(fn () => $this->migration()->down(), 'Provider lifecycle shadow history exists; fix forward or restore a verified backup.');
        DB::table('tx_credential_provider_shadow_lifecycle')->update(['last_fact_version' => '9223372036854775807']);
        $this->assertRejected(fn () => $this->revoke($this->subject(), 'fixture_overflow'), 'VERSION_CONFLICT');
        $this->assertSame(1, DB::table('tx_credential_provider_shadow_revocation')->count());
        $this->assertSame(1, DB::table('tx_credential_provider_shadow_operation')->count());
    }

    public function test_eight_independent_sessions_replay_one_core_fact_and_one_private_revoke(): void
    {
        $this->requireMysqlFork(); $scope = $this->subject(); $issued = $this->issue($scope);
        $results = $this->race(fn () => $this->revoke($scope));
        // A replay may query the recorded ID before its creator has dispatched. That response
        // is legitimately unknown; every worker must still converge on the same durable ID.
        $this->assertCount(8, array_filter($results, fn ($r) => in_array($r['result']['status'] ?? '', ['succeeded', 'unknown'], true)), json_encode($results));
        $this->assertCount(1, array_unique(array_map(fn ($r) => $r['result']['operation_ref'], $results)));
        $final = $this->provider()->query($scope, $results[0]['result']['operation_ref'], time() + 5);
        $this->assertSame('succeeded', $final['status']);
        $this->assertSame(1, DB::table('tx_credential_provider_shadow_revocation')->count());
        $this->assertCount(2, $this->privateState()['operations']);
        $this->assertArrayNotHasKey('fixture_secret', $this->privateState()['operations'][$issued['operation_ref']]);
    }

    public function test_real_session_issue_waits_for_uncommitted_disable_fence_then_refuses_dispatch(): void
    {
        $this->assertIssueWaitsForFence('disable');
    }

    public function test_real_session_issue_waits_for_uncommitted_revocation_fact_then_refuses_dispatch(): void
    {
        $this->assertIssueWaitsForFence('revoke');
    }

    private function assertIssueWaitsForFence(string $kind): void
    {
        $this->requireMysqlFork(); $scope = $this->subject();
        $directory = sys_get_temp_dir().'/txboard-disable-barrier-'.bin2hex(random_bytes(8)); mkdir($directory, 0700);
        $connection = DB::getDefaultConnection(); DB::disconnect($connection); $pid = pcntl_fork();
        if ($pid === 0) {
            DB::purge($connection); $until = microtime(true) + 20;
            while (!is_file($directory.'/fenced') && microtime(true) < $until) { usleep(1000); }
            touch($directory.'/attempted');
            try { $result = ['result' => $this->issue($scope)]; } catch (\Throwable $error) { $result = ['error' => $error->getMessage()]; }
            file_put_contents($directory.'/result', json_encode($result)); exit(0);
        }
        if ($pid < 0) { throw new RuntimeException('Fork failed'); }
        DB::purge($connection); $observed = false;
        try {
            DB::connection()->listen(function ($query) use ($directory, &$observed, $kind) {
                $matches = $kind === 'disable'
                    ? str_starts_with($query->sql, 'update') && str_contains($query->sql, 'tx_credential_provider_shadow_registration')
                    : str_starts_with($query->sql, 'insert into') && str_contains($query->sql, 'tx_credential_provider_shadow_revocation');
                if (!$observed && $matches) {
                    $observed = true; touch($directory.'/fenced'); $until = microtime(true) + 10;
                    while (!is_file($directory.'/attempted') && microtime(true) < $until) { usleep(1000); }
                    $this->assertFileExists($directory.'/attempted'); usleep(100000);
                    $this->assertFileDoesNotExist($directory.'/result', 'Issue must wait for the registration fence transaction.');
                }
            });
            if ($kind === 'disable') { $this->lifecycle()->disable('fixture_provider', 'fixture_disable'); }
            else { $this->revoke($scope); }
            pcntl_waitpid($pid, $status); $pid = 0;
            $this->assertTrue($observed); $this->assertSame(0, pcntl_wexitstatus($status));
            $result = json_decode(file_get_contents($directory.'/result'), true); $this->assertSame($kind === 'disable' ? 'PROVIDER_DISABLED' : 'REVOKED', $result['error']);
            $this->assertSame(0, DB::table('tx_credential_provider_shadow_operation')->where('operation', 'issue')->count());
            if ($kind === 'disable') { $this->assertSame([], glob($this->privateRoot.'/*') ?: []); }
            else { $this->assertCount(1, $this->privateState()['operations']); $this->assertCount(1, $this->privateState()['tombstones']); }
        } finally {
            if ($pid > 0) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
            foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); } rmdir($directory); DB::purge($connection);
        }
    }

    private function subject(): FixtureScope
    {
        $sub = DB::table('tx_subscription')->insertGetId(['user_id' => 1, 'status' => 'active', 'starts_at' => 1, 'expires_at' => time() + 3600, 'created_at' => 1, 'updated_at' => 1]);
        $terms = DB::table('tx_subscription_terms')->insertGetId(['subscription_id' => $sub, 'version_no' => 1, 'effective_from' => 1,
            'quota_bytes' => 1000, 'billing_timezone' => 'UTC', 'reset_policy' => '{"method":1}', 'content_hash' => str_repeat('a', 64), 'created_at' => 1]);
        DB::table('tx_subscription')->where('id', $sub)->update(['current_terms_id' => $terms]);
        $id = DB::table('tx_access_subject')->insertGetId(['subscription_id' => $sub, 'provider_id' => 'fixture_provider',
            'provider_subject_ref' => 'core_pending_'.bin2hex(random_bytes(8)), 'status' => 'pending', 'auth_version' => 1, 'created_at' => 1, 'updated_at' => 1]);
        return new FixtureScope('fixture_provider', (string) $id);
    }

    private function issue(FixtureScope $scope, string $key = 'fixture_issue'): array
    {
        return $this->provider()->issue($scope, $key, ['expected_subject_version' => '1', 'credential_profile_id' => 'synthetic-v1',
            'not_before_at' => time() + 5, 'credential_expires_at' => time() + 300], time() + 5);
    }
    private function revoke(FixtureScope $scope, string $key = 'fixture_revoke'): array { return $this->provider()->revoke($scope, $key,
        ['expected_subject_version' => '1', 'reason_code' => 'operator_revoke'], time() + 5); }
    private function subjectRow(FixtureScope $scope): object { return DB::table('tx_access_subject')->where('id', $scope->subjectId)->first(); }
    private function register(): string { return $this->provider()->register('fixture_provider', '1', ['issue', 'query', 'revoke']); }
    private function provider(): FixtureProviderService { return app(FixtureProviderService::class); }
    private function lifecycle(): FixtureLifecycleService { return app(FixtureLifecycleService::class); }
    private function privateState(): array { return json_decode(file_get_contents($this->privateRoot.'/fixture_provider/state.json'), true, flags: JSON_THROW_ON_ERROR); }
    private function migration(): object { return require database_path('migrations/2026_10_12_000009_create_credential_provider_lifecycle_shadow_tables.php'); }
    private function snapshot(): array { $state = []; foreach (self::TABLES as $table) {
        $columns = Schema::getColumnListing($table); $key = in_array('id', $columns, true) ? 'id' : $columns[0];
        $state[$table] = DB::table($table)->orderBy($key)->get()->map(fn ($r) => (array) $r)->all(); } return $state; }
    private function assertRejected(callable $operation, string $code): void { try { $operation(); $this->fail('Unsafe lifecycle operation accepted'); }
        catch (DomainException|RuntimeException $error) { $this->assertSame($code, $error->getMessage()); } }
    private function clearMysqlFixtures(): void { if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') { throw new RuntimeException('PROVIDER_TEST_DEDICATED_DATABASE_REQUIRED'); }
        Schema::disableForeignKeyConstraints(); try { foreach (self::TABLES as $table) { Schema::dropIfExists($table); } } finally { Schema::enableForeignKeyConstraints(); } }
    private function requireMysqlFork(): void { if (DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) { $this->markTestSkipped('Requires leased MariaDB and independent Core/Provider processes.'); } }

    private function race(callable $operation): array
    {
        $directory = sys_get_temp_dir().'/txboard-revoke-race-'.bin2hex(random_bytes(8)); mkdir($directory, 0700);
        $connection = DB::getDefaultConnection(); $children = [];
        try {
            DB::disconnect($connection);
            for ($worker = 0; $worker < 8; $worker++) {
                $pid = pcntl_fork(); if ($pid === -1) { throw new RuntimeException('Fork failed'); }
                if ($pid === 0) {
                    DB::purge($connection); touch($directory.'/ready-'.$worker); $until = microtime(true) + 20;
                    while (!is_file($directory.'/go') && microtime(true) < $until) { usleep(1000); }
                    try { $result = ['result' => $operation()]; } catch (\Throwable $error) { $result = ['error' => $error->getMessage()]; }
                    file_put_contents($directory.'/worker-'.$worker, json_encode($result)); exit(0);
                } $children[] = $pid;
            }
            $until = microtime(true) + 20; while (count(glob($directory.'/ready-*') ?: []) !== 8 && microtime(true) < $until) { usleep(1000); }
            $this->assertCount(8, glob($directory.'/ready-*') ?: []); touch($directory.'/go');
            foreach ($children as $pid) { pcntl_waitpid($pid, $status); $this->assertSame(0, pcntl_wexitstatus($status)); } $children = []; DB::purge($connection);
            $results = []; for ($worker = 0; $worker < 8; $worker++) { $results[] = json_decode(file_get_contents($directory.'/worker-'.$worker), true); } return $results;
        } finally {
            foreach ($children as $pid) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
            foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); } rmdir($directory); DB::purge($connection);
        }
    }
}
