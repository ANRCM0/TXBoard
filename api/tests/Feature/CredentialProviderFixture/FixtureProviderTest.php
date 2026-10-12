<?php

namespace Tests\Feature\CredentialProviderFixture;

use App\Domains\CredentialProviderFixture\FixtureCodec;
use App\Domains\CredentialProviderFixture\FixtureProcess;
use App\Domains\CredentialProviderFixture\FixtureProviderService;
use App\Domains\CredentialProviderFixture\FixtureScope;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class FixtureProviderTest extends TestCase
{
    private string $privateRoot;
    private const TABLES = ['tx_credential_provider_shadow_operation', 'tx_credential_provider_shadow_registration',
        'tx_usage_adjustment', 'tx_access_subject', 'tx_legacy_subscription_map', 'tx_legacy_subscription_exception',
        'tx_migration_checkpoint', 'tx_subscription_slot', 'tx_usage_period', 'tx_subscription_terms_transition', 'tx_subscription_terms', 'tx_subscription'];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CORE_PROVIDER_TEST_MYSQL') === '1') {
            config(['database.default' => 'provider_fixture_mysql_testing', 'database.connections.provider_fixture_mysql_testing' => config('database.connections.mysql')]);
            DB::purge('provider_fixture_mysql_testing');
            $this->clearMysqlFixtures();
        } else {
            config(['database.default' => 'provider_fixture_testing', 'database.connections.provider_fixture_testing' =>
                array_merge(config('database.connections.sqlite'), ['database' => ':memory:', 'foreign_key_constraints' => true])]);
            DB::purge('provider_fixture_testing');
        }
        $this->privateRoot = sys_get_temp_dir().'/txboard-provider-private-'.bin2hex(random_bytes(8));
        mkdir($this->privateRoot, 0700);
        config(['core_shadow.isolated_copy' => true, 'credential_provider_fixture.enabled' => true,
            'credential_provider_fixture.private_root' => $this->privateRoot,
            'credential_provider_fixture.wall_timeout_seconds' => 1.0, 'credential_provider_fixture.test_fault' => null]);
        (require database_path('migrations/2026_10_12_000001_create_subscription_shadow_tables.php'))->up();
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        if (getenv('CORE_PROVIDER_TEST_MYSQL') === '1') { $this->clearMysqlFixtures(); DB::purge('provider_fixture_mysql_testing'); }
        if (isset($this->privateRoot) && is_dir($this->privateRoot)) {
            foreach (glob($this->privateRoot.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
                foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); }
                rmdir($directory);
            }
            rmdir($this->privateRoot);
        }
        DB::purge('provider_fixture_testing');
        parent::tearDown();
    }

    public function test_independent_process_owns_secret_and_core_only_stores_nonsecret_receipt(): void
    {
        $scope = $this->subject();
        $this->register();
        $before = DB::table('tx_access_subject')->first();
        Log::spy();
        putenv('DB_PASSWORD=provider-test-core-password');
        putenv('APP_KEY=provider-test-core-key');
        try { $result = $this->issue($scope); }
        finally { putenv('DB_PASSWORD'); putenv('APP_KEY'); }
        $this->assertSame('succeeded', $result['status'], json_encode($result));
        $private = $this->privateState();
        $this->assertCount(1, $private['operations']);
        $record = reset($private['operations']);
        $this->assertNotSame(getmypid(), $record['provider_pid']);
        $this->assertFalse($record['core_env_present']);
        $this->assertFalse($record['core_file_readable']);
        $this->assertFalse($record['php_network_functions_enabled']);
        $this->assertSame(64, strlen($record['fixture_secret']));
        $this->assertNotSame(substr(hash('sha256', $record['fixture_secret']), 0, 32), substr($result['result']['credential_ref'], 3));
        $core = FixtureCodec::json(['registration' => (array) DB::table('tx_credential_provider_shadow_registration')->first(),
            'operation' => (array) DB::table('tx_credential_provider_shadow_operation')->first(), 'dto' => $result]);
        $this->assertStringNotContainsString($record['fixture_secret'], $core);
        $this->assertStringNotContainsString('provider-test-core-password', $core);
        $this->assertStringNotContainsString($this->privateRoot, $core);
        $this->assertEquals($before, DB::table('tx_access_subject')->first());
        $this->assertSame(0600, fileperms($this->privateRoot.'/fixture_provider/state.json') & 0777);
        $this->assertSame(0700, fileperms($this->privateRoot.'/fixture_provider') & 0777);
        foreach (['debug', 'info', 'warning', 'error', 'critical', 'log'] as $method) { Log::shouldNotHaveReceived($method); }
    }

    public function test_default_disabled_and_missing_provider_do_not_start_process(): void
    {
        $scope = $this->subject();
        $this->assertRejected(fn () => $this->issue($scope), 'CAPABILITY_UNAVAILABLE');
        config(['credential_provider_fixture.enabled' => false]);
        $this->assertRejected(fn () => $this->register(), 'CAPABILITY_UNAVAILABLE');
        $this->assertSame([], glob($this->privateRoot.'/*') ?: []);
        $this->assertSame(0, DB::table('tx_credential_provider_shadow_operation')->count());
        $config = require config_path('credential_provider_fixture.php');
        $this->assertFalse($config['enabled']);
    }

    public function test_response_loss_is_unknown_and_replay_queries_original_operation_after_process_restart(): void
    {
        $scope = $this->subject(); $this->register();
        $input = $this->input();
        config(['credential_provider_fixture.test_fault' => 'after_commit_drop']);
        $unknown = $this->service()->issue($scope, 'fixture_lost', $input, time() + 5);
        $this->assertSame('unknown', $unknown['status']);
        $this->assertSame('RUNTIME_UNKNOWN', $unknown['error_code']);
        $privateBefore = $this->privateState();
        config(['credential_provider_fixture.test_fault' => null]);
        $restored = (new FixtureProviderService(new FixtureProcess(), app(\App\Services\Subscription\SubscriptionTermsTimeline::class)))
            ->issue($scope, 'fixture_lost', $input, time() + 5);
        $this->assertSame('succeeded', $restored['status'], json_encode($restored));
        $this->assertSame($unknown['operation_ref'], $restored['operation_ref']);
        $this->assertSame(FixtureCodec::hash($privateBefore), FixtureCodec::hash($this->privateState()));
        $this->assertSame($restored, $this->service()->query($scope, $restored['operation_ref'], time() + 5));
        $this->assertSame(1, DB::table('tx_credential_provider_shadow_operation')->count());
    }

    public function test_timeout_after_private_commit_recovers_by_query_without_second_secret(): void
    {
        $scope = $this->subject(); $this->register();
        config(['credential_provider_fixture.wall_timeout_seconds' => 0.15, 'credential_provider_fixture.test_fault' => 'after_commit_sleep']);
        $before = microtime(true);
        $unknown = $this->issue($scope);
        $this->assertLessThan(1.0, microtime(true) - $before);
        $this->assertSame('unknown', $unknown['status']);
        $private = $this->privateState();
        config(['credential_provider_fixture.wall_timeout_seconds' => 1.0, 'credential_provider_fixture.test_fault' => null]);
        $success = $this->service()->query($scope, $unknown['operation_ref'], time() + 5);
        $this->assertSame('succeeded', $success['status']);
        $this->assertSame(FixtureCodec::hash($private), FixtureCodec::hash($this->privateState()));
    }

    public function test_core_finalization_failure_keeps_durable_unknown_then_queries_the_provider_receipt(): void
    {
        $scope = $this->subject(); $this->register();
        $sql = DB::getDriverName() === 'mysql'
            ? "CREATE TRIGGER cp_finalize_fail BEFORE UPDATE ON tx_credential_provider_shadow_operation FOR EACH ROW BEGIN IF NEW.status = 'succeeded' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected provider finalization failure'; END IF; END"
            : "CREATE TRIGGER cp_finalize_fail BEFORE UPDATE ON tx_credential_provider_shadow_operation WHEN NEW.status = 'succeeded' BEGIN SELECT RAISE(ABORT, 'injected provider finalization failure'); END";
        DB::unprepared($sql);
        $unknown = $this->issue($scope);
        $this->assertSame('unknown', $unknown['status']);
        $this->assertSame('CORE_FINALIZATION_UNKNOWN', $unknown['error_code']);
        $this->assertCount(1, $this->privateState()['operations']);
        DB::unprepared('DROP TRIGGER cp_finalize_fail');
        $restored = $this->service()->query($scope, $unknown['operation_ref'], time() + 5);
        $this->assertSame('succeeded', $restored['status']);
        $this->assertCount(1, $this->privateState()['operations']);
    }

    public function test_recording_failure_prevents_any_provider_execution(): void
    {
        $scope = $this->subject(); $this->register();
        $sql = DB::getDriverName() === 'mysql'
            ? "CREATE TRIGGER cp_record_fail BEFORE INSERT ON tx_credential_provider_shadow_operation FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected record failure'"
            : "CREATE TRIGGER cp_record_fail BEFORE INSERT ON tx_credential_provider_shadow_operation BEGIN SELECT RAISE(ABORT, 'injected record failure'); END";
        DB::unprepared($sql);
        try { $this->issue($scope); $this->fail('Undurable operation invoked runtime'); }
        catch (QueryException) { $this->assertTrue(true); }
        $this->assertSame([], glob($this->privateRoot.'/*') ?: []);
        $this->assertSame(0, DB::table('tx_credential_provider_shadow_operation')->count());
    }

    public function test_same_key_changed_input_and_second_issue_key_for_subject_are_rejected(): void
    {
        $scope = $this->subject(); $this->register(); $input = $this->input();
        $first = $this->service()->issue($scope, 'fixture_same', $input, time() + 5);
        $this->assertSame($first, $this->service()->issue($scope, 'fixture_same', $input, time() + 5));
        $changed = $input; $changed['credential_expires_at']++;
        $this->assertRejected(fn () => $this->service()->issue($scope, 'fixture_same', $changed, time() + 5), 'IDEMPOTENCY_CONFLICT');
        $this->assertRejected(fn () => $this->service()->issue($scope, 'fixture_other', $input, time() + 5), 'SUBJECT_BINDING_CONFLICT');
        $this->assertCount(1, $this->privateState()['operations']);
    }

    public function test_query_rejects_other_subject_provider_and_operator_scope(): void
    {
        $scope = $this->subject(); $this->register();
        $receipt = $this->issue($scope);
        $other = $this->subject();
        $this->assertRejected(fn () => $this->service()->query($other, $receipt['operation_ref'], time() + 5), 'UNAUTHORIZED_SCOPE');
        $this->service()->register('other_provider', '1');
        $this->assertRejected(fn () => $this->service()->query(new FixtureScope('other_provider', $scope->subjectId), $receipt['operation_ref'], time() + 5), 'UNAUTHORIZED_SCOPE');
        $this->assertRejected(fn () => new FixtureScope('fixture_provider', $scope->subjectId, 'untrusted-user'), 'UNAUTHORIZED_SCOPE');
        $this->assertRejected(fn () => $this->issue(new FixtureScope('other_provider', $scope->subjectId)), 'UNAUTHORIZED_SCOPE');
    }

    public function test_registration_epoch_digest_operation_and_profile_grants_are_enforced(): void
    {
        $scope = $this->subject(); $id = $this->register();
        $this->assertSame($id, $this->register());
        $this->assertRejected(fn () => $this->service()->register('fixture_provider', '2'), 'PROVIDER_REGISTRATION_CONFLICT');
        $this->assertRejected(fn () => $this->service()->register('fixture_provider', '1', ['issue', 'render']), 'UNSUPPORTED_OPERATION');
        DB::table('tx_credential_provider_shadow_registration')->update(['operation_grants' => '["query"]']);
        $this->assertRejected(fn () => $this->issue($scope), 'UNSUPPORTED_OPERATION');
        DB::table('tx_credential_provider_shadow_registration')->update(['operation_grants' => '["issue","query"]', 'profile_grants' => '["unknown"]']);
        $this->assertRejected(fn () => $this->issue($scope), 'UNSUPPORTED_PROFILE');
        DB::table('tx_credential_provider_shadow_registration')->update(['profile_grants' => '["synthetic-v1"]', 'script_digest' => str_repeat('0', 64)]);
        $this->assertRejected(fn () => $this->issue($scope), 'RUNTIME_UNAVAILABLE');
        $this->assertSame([], glob($this->privateRoot.'/*') ?: []);
    }

    public function test_unsupported_render_delivery_import_and_other_operations_are_explicitly_refused(): void
    {
        foreach (['parse', 'render', 'delivery', 'rotate', 'revoke', 'import', 'shell', 'export'] as $operation) {
            $this->assertRejected(fn () => $this->service()->unsupported($operation), 'UNSUPPORTED_OPERATION');
        }
        $this->assertSame([], glob($this->privateRoot.'/*') ?: []);
    }

    public function test_scope_versions_revocation_deadlines_and_extra_input_fields_are_refused(): void
    {
        $scope = $this->subject(); $this->register(); $input = $this->input();
        $this->assertRejected(fn () => $this->service()->issue($scope, 'fixture_bad', $input, time() + 60), 'DEADLINE_EXCEEDED');
        $this->assertRejected(fn () => $this->service()->issue($scope, 'fixture_bad', $input + ['password' => 'do-not-store'], time() + 5), 'PROVIDER_INPUT_INVALID');
        $bad = $input; $bad['expected_subject_version'] = '2';
        $this->assertRejected(fn () => $this->service()->issue($scope, 'fixture_bad', $bad, time() + 5), 'VERSION_CONFLICT');
        DB::table('tx_access_subject')->where('id', $scope->subjectId)->update(['status' => 'revoked', 'revoked_at' => time()]);
        $this->assertRejected(fn () => $this->issue($scope), 'REVOKED');
        $this->assertSame(0, DB::table('tx_credential_provider_shadow_operation')->count());
    }

    public function test_oversized_and_malformed_runtime_responses_are_discarded_without_sql_or_log_leakage(): void
    {
        $this->register(); Log::spy();
        foreach (['oversize' => 'PROVIDER_RESPONSE_LIMIT', 'malformed' => 'PROVIDER_INVALID_RESPONSE', 'wrong_identity' => 'PROVIDER_INVALID_RESPONSE'] as $fault => $code) {
            $scope = $this->subject();
            config(['credential_provider_fixture.test_fault' => $fault]);
            $result = $this->issue($scope, 'fixture_'.$fault);
            $this->assertSame('unknown', $result['status']);
            $this->assertSame($code, $result['error_code']);
            $this->assertNull($result['result']);
        }
        $this->assertStringNotContainsString('blocked-fixture-marker', FixtureCodec::json(DB::table('tx_credential_provider_shadow_operation')->get()->map(fn ($r) => (array) $r)->all()));
        foreach (['error', 'warning', 'debug', 'log'] as $method) { Log::shouldNotHaveReceived($method); }
    }

    public function test_unknown_absent_private_operation_is_never_reissued_and_disabled_runtime_keeps_recovery_scope(): void
    {
        $scope = $this->subject(); $this->register();
        config(['credential_provider_fixture.private_root' => null]);
        $unknown = $this->issue($scope);
        $this->assertSame('unknown', $unknown['status']);
        config(['credential_provider_fixture.private_root' => $this->privateRoot]);
        DB::table('tx_credential_provider_shadow_registration')->update(['enabled' => false]);
        $query = $this->service()->query($scope, $unknown['operation_ref'], time() + 5);
        $this->assertSame('unknown', $query['status']);
        $this->assertSame('OPERATION_UNKNOWN', $query['error_code']);
        $this->assertFileDoesNotExist($this->privateRoot.'/fixture_provider/state.json');
        $this->assertRejected(fn () => $this->issue($this->subject(), 'fixture_disabled'), 'PROVIDER_DISABLED');
    }

    public function test_schema_replay_hash_damage_and_nonempty_rollback_are_refused(): void
    {
        $scope = $this->subject(); $this->register(); $receipt = $this->issue($scope);
        $before = DB::table('tx_credential_provider_shadow_operation')->first();
        $this->migration()->up();
        $this->assertEquals($before, DB::table('tx_credential_provider_shadow_operation')->first());
        $this->assertRejected(fn () => $this->migration()->down(), 'Provider shadow history exists; fix forward or restore a verified backup.');
        DB::table('tx_credential_provider_shadow_operation')->update(['result_hash' => str_repeat('0', 64)]);
        $this->assertRejected(fn () => $this->service()->query($scope, $receipt['operation_ref'], time() + 5), 'PROVIDER_OPERATION_DAMAGED');
    }

    public function test_transport_input_limit_private_directory_policy_and_outer_transactions_fail_closed(): void
    {
        $this->assertRejected(fn () => (new FixtureProcess())->call(['deadline_at' => time() + 5, 'padding' => str_repeat('x', 4096)]), 'PROVIDER_LIMIT_INVALID');
        $scope = $this->subject(); $this->register();
        DB::beginTransaction();
        try { $this->assertRejected(fn () => $this->issue($scope), 'PROVIDER_OUTER_TRANSACTION_UNSUPPORTED'); }
        finally { DB::rollBack(); }
        chmod($this->privateRoot, 0755);
        clearstatcache(true, $this->privateRoot);
        $unknown = $this->issue($scope);
        $this->assertSame('RUNTIME_UNAVAILABLE', $unknown['error_code']);
        $this->assertSame('unknown', $unknown['status']);
        $this->assertSame([], glob($this->privateRoot.'/*') ?: []);
        chmod($this->privateRoot, 0700);
    }

    public function test_unknown_issue_replay_cannot_bypass_the_query_operation_grant(): void
    {
        $scope = $this->subject();
        $this->service()->register('fixture_provider', '1', ['issue']);
        $input = $this->input();
        config(['credential_provider_fixture.test_fault' => 'after_commit_drop']);
        $unknown = $this->service()->issue($scope, 'fixture_no_query', $input, time() + 5);
        $this->assertSame('unknown', $unknown['status']);
        $before = $this->privateState();
        config(['credential_provider_fixture.test_fault' => null]);
        $this->assertRejected(fn () => $this->service()->issue($scope, 'fixture_no_query', $input, time() + 5), 'UNSUPPORTED_OPERATION');
        $this->assertSame(FixtureCodec::hash($before), FixtureCodec::hash($this->privateState()));
    }

    public function test_current_registration_grants_are_rechecked_after_record_commit_before_runtime_dispatch(): void
    {
        $scope = $this->subject(); $this->register();
        $changed = false;
        DB::connection()->listen(function ($query) use (&$changed) {
            if (!$changed && str_starts_with($query->sql, 'insert into') && str_contains($query->sql, 'tx_credential_provider_shadow_operation')) {
                $changed = true;
                // A trusted operator change committed with the recording transaction, after
                // its old registration object was read, must govern the subsequent IPC.
                DB::table('tx_credential_provider_shadow_registration')->update(['operation_grants' => '["query"]']);
            }
        });
        $this->assertRejected(fn () => $this->issue($scope), 'UNSUPPORTED_OPERATION');
        $this->assertTrue($changed);
        $this->assertSame('recorded', DB::table('tx_credential_provider_shadow_operation')->value('status'));
        $this->assertSame([], glob($this->privateRoot.'/*') ?: []);
        $ref = DB::table('tx_credential_provider_shadow_operation')->value('operation_ref');
        $query = $this->service()->query($scope, $ref, time() + 5);
        $this->assertSame('unknown', $query['status']);
        $this->assertSame('OPERATION_UNKNOWN', $query['error_code']);
        $this->assertFileDoesNotExist($this->privateRoot.'/fixture_provider/state.json');
    }

    public function test_real_core_sessions_replay_one_durable_operation_and_one_private_issuance(): void
    {
        $this->requireMysqlFork();
        $scope = $this->subject(); $this->register(); $input = $this->input();
        $results = $this->race(fn () => $this->service()->issue($scope, 'fixture_race', $input, time() + 5));
        $this->assertCount(8, array_filter($results, fn ($r) => isset($r['result'])), json_encode($results));
        $references = array_unique(array_map(fn ($r) => $r['result']['operation_ref'], $results));
        $this->assertCount(1, $references);
        $final = $this->service()->query($scope, reset($references), time() + 5);
        $this->assertSame('succeeded', $final['status'], json_encode($results));
        $this->assertCount(1, $this->privateState()['operations']);
        $this->assertSame(1, DB::table('tx_credential_provider_shadow_operation')->count());
    }

    public function test_subject_revoked_after_recording_is_rechecked_before_private_issuance(): void
    {
        $scope = $this->subject(); $this->register();
        $changed = false;
        DB::connection()->listen(function ($query) use (&$changed, $scope) {
            if (!$changed && str_starts_with($query->sql, 'insert into') && str_contains($query->sql, 'tx_credential_provider_shadow_operation')) {
                $changed = true;
                DB::table('tx_access_subject')->where('id', $scope->subjectId)->update(['status' => 'revoked', 'revoked_at' => time()]);
            }
        });
        $this->assertRejected(fn () => $this->issue($scope), 'REVOKED');
        $this->assertSame('recorded', DB::table('tx_credential_provider_shadow_operation')->value('status'));
        $this->assertSame([], glob($this->privateRoot.'/*') ?: []);
    }

    public function test_credential_expiry_must_fit_the_current_projected_terms(): void
    {
        $scope = $this->subject(); $this->register();
        DB::table('tx_subscription_terms')->update(['effective_until' => time() + 60]);
        $this->assertRejected(fn () => $this->issue($scope), 'UNAUTHORIZED_SCOPE');
        $this->assertSame(0, DB::table('tx_credential_provider_shadow_operation')->count());
        $this->assertSame([], glob($this->privateRoot.'/*') ?: []);
    }

    public function test_terms_expiring_after_recording_prevent_runtime_dispatch(): void
    {
        $scope = $this->subject(); $this->register(); $changed = false;
        DB::connection()->listen(function ($query) use (&$changed) {
            if (!$changed && str_starts_with($query->sql, 'insert into') && str_contains($query->sql, 'tx_credential_provider_shadow_operation')) {
                $changed = true;
                DB::table('tx_subscription_terms')->update(['effective_until' => time()]);
            }
        });
        $this->assertRejected(fn () => $this->issue($scope), 'UNAUTHORIZED_SCOPE');
        $this->assertTrue($changed);
        $this->assertSame('recorded', DB::table('tx_credential_provider_shadow_operation')->value('status'));
        $this->assertSame([], glob($this->privateRoot.'/*') ?: []);
    }

    public function test_real_core_sessions_different_keys_for_one_subject_allow_one_issuance(): void
    {
        $this->requireMysqlFork();
        $scope = $this->subject(); $this->register(); $input = $this->input();
        $results = $this->race(fn (int $worker) => $this->service()->issue($scope, 'fixture_compete_'.$worker, $input, time() + 5));
        $this->assertCount(1, array_filter($results, fn ($r) => isset($r['result'])), json_encode($results));
        $this->assertCount(7, array_filter($results, fn ($r) => ($r['error'] ?? '') === 'SUBJECT_BINDING_CONFLICT'));
        $this->assertCount(1, $this->privateState()['operations']);
        $this->assertSame(1, DB::table('tx_credential_provider_shadow_operation')->count());
    }

    private function race(callable $operation): array
    {
        $directory = sys_get_temp_dir().'/txboard-provider-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $connection = DB::getDefaultConnection(); $children = [];
        try {
            DB::disconnect($connection);
            for ($worker = 0; $worker < 8; $worker++) {
                $pid = pcntl_fork();
                if ($pid === -1) { throw new RuntimeException('Provider Core worker fork failed'); }
                if ($pid === 0) {
                    DB::purge($connection);
                    touch($directory.'/ready-'.$worker);
                    $until = microtime(true) + 20;
                    while (!is_file($directory.'/go') && microtime(true) < $until) { usleep(10000); }
                    if (!is_file($directory.'/go')) { exit(2); }
                    try { $result = ['result' => $operation($worker)]; }
                    catch (\Throwable $error) { $result = ['error' => $error->getMessage()]; }
                    file_put_contents($directory.'/worker-'.$worker, json_encode($result, JSON_THROW_ON_ERROR));
                    exit(0);
                }
                $children[] = $pid;
            }
            $until = microtime(true) + 20;
            while (count(glob($directory.'/ready-*') ?: []) !== 8 && microtime(true) < $until) { usleep(10000); }
            $this->assertCount(8, glob($directory.'/ready-*') ?: []);
            touch($directory.'/go');
            foreach ($children as $pid) { pcntl_waitpid($pid, $status); $this->assertSame(0, pcntl_wexitstatus($status)); }
            $children = [];
            DB::purge($connection);
            $results = [];
            for ($worker = 0; $worker < 8; $worker++) { $results[] = json_decode(file_get_contents($directory.'/worker-'.$worker), true, flags: JSON_THROW_ON_ERROR); }
            return $results;
        } finally {
            foreach ($children as $pid) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
            foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); }
            rmdir($directory);
            DB::purge($connection);
        }
    }

    private function requireMysqlFork(): void
    {
        if (DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires leased dedicated MariaDB and independent Core/Provider processes.');
        }
    }

    private function subject(string $provider = 'fixture_provider'): FixtureScope
    {
        $sub = DB::table('tx_subscription')->insertGetId(['user_id' => 1, 'status' => 'active', 'starts_at' => 1,
            'expires_at' => time() + 3600, 'created_at' => 1, 'updated_at' => 1]);
        $terms = DB::table('tx_subscription_terms')->insertGetId(['subscription_id' => $sub, 'version_no' => 1,
            'effective_from' => 1, 'quota_bytes' => 1000, 'billing_timezone' => 'UTC', 'reset_policy' => '{"method":1}',
            'content_hash' => str_repeat('a', 64), 'created_at' => 1]);
        DB::table('tx_subscription')->where('id', $sub)->update(['current_terms_id' => $terms]);
        $subject = DB::table('tx_access_subject')->insertGetId(['subscription_id' => $sub, 'provider_id' => $provider,
            'provider_subject_ref' => 'core_pending_'.bin2hex(random_bytes(8)), 'status' => 'pending', 'auth_version' => 1,
            'created_at' => 1, 'updated_at' => 1]);
        return new FixtureScope($provider, (string) $subject);
    }

    private function input(): array
    {
        return ['expected_subject_version' => '1', 'credential_profile_id' => 'synthetic-v1',
            'not_before_at' => time() + 5, 'credential_expires_at' => time() + 300];
    }

    private function issue(FixtureScope $scope, string $key = 'fixture_issue'): array { return $this->service()->issue($scope, $key, $this->input(), time() + 5); }
    private function register(): string { return $this->service()->register('fixture_provider', '1'); }
    private function service(): FixtureProviderService { return app(FixtureProviderService::class); }
    private function privateState(): array { return json_decode(file_get_contents($this->privateRoot.'/fixture_provider/state.json'), true, flags: JSON_THROW_ON_ERROR); }
    private function migration(): object { return require database_path('migrations/2026_10_12_000007_create_credential_provider_shadow_tables.php'); }
    private function assertRejected(callable $operation, string $code): void
    {
        try { $operation(); $this->fail('Unsafe fixture operation accepted'); }
        catch (DomainException|RuntimeException $error) { $this->assertSame($code, $error->getMessage()); }
    }

    private function clearMysqlFixtures(): void
    {
        if (DB::connection()->getDatabaseName() !== 'txboard_concurrency_test') { throw new RuntimeException('PROVIDER_TEST_DEDICATED_DATABASE_REQUIRED'); }
        Schema::disableForeignKeyConstraints();
        try { foreach (self::TABLES as $table) { Schema::dropIfExists($table); } }
        finally { Schema::enableForeignKeyConstraints(); }
    }
}
