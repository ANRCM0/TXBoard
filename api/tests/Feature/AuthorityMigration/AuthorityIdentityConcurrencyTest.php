<?php

namespace Tests\Feature\AuthorityMigration;

use App\Domains\AuthorityMigration\AuthorityAdmissionRegistry;
use App\Domains\AuthorityMigration\AuthorityFenceException;
use App\Domains\Identity\AccountSqlPhase;
use App\Models\Setting as SettingModel;
use App\Models\User;
use App\Services\AuthService;
use App\Services\Plugin\HookManager;
use App\Support\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthorityIdentityFixture;
use Tests\TestCase;

/** Real native phases, independent PDOs and prepare lock waits on the guarded dedicated database. */
class AuthorityIdentityConcurrencyTest extends TestCase
{
    use AuthorityIdentityFixture;

    private bool $databaseConfigured = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->identityMysql() || !function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Requires AUTHORITY_IDENTITY_MYSQL=1, independent MySQL processes and SIGKILL.');
        }
        // The shared fixture validates the exact database, account and loopback
        // endpoint before any destructive setup or cleanup.
        $this->configureIdentityDatabase();
        $this->databaseConfigured = true;
        config(['authority_writer_fence.enabled' => false, 'core_shadow.isolated_copy' => true,
            'cache.default' => 'array', 'cache.setting_store' => 'array']);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        Bus::fake();
        HookManager::reset();
        config(['authority_writer_fence' => ['enabled' => true, 'worker_epoch' => '1', 'worker_role' => 'legacy']]);
        $this->identity();
    }

    protected function tearDown(): void
    {
        HookManager::reset();
        if ($this->databaseConfigured) {
            $this->clearIdentityDatabase();
            DB::purge($this->identityConnection());
        }
        parent::tearDown();
    }

    public function test_native_session_issue_commits_before_independent_prepare6(): void
    {
        $this->ordering('session', 'commit');
    }

    public function test_native_session_issue_rollback_unblocks_prepare6_without_a_token(): void
    {
        $this->ordering('session', 'rollback');
    }

    public function test_sigkill_before_native_session_commit_rolls_back_and_unblocks_prepare6(): void
    {
        $this->ordering('session', 'kill');
    }

    public function test_native_setting_update_commits_before_independent_prepare6(): void
    {
        $this->ordering('setting', 'commit');
    }

    public function test_native_setting_update_rollback_unblocks_prepare6_and_preserves_original_value(): void
    {
        $this->ordering('setting', 'rollback');
    }

    private function ordering(string $kind, string $mode): void
    {
        $user = $this->user();
        $key = 'identity_concurrency_fixture';
        SettingModel::createOrUpdate($key, 'original');
        $owner = $kind === 'session' ? AccountSqlPhase::SESSION_ISSUE : AccountSqlPhase::SETTINGS_WRITE;
        $domainTable = $kind === 'session' ? 'tx_user' : 'tx_settings';
        $mutationTable = $kind === 'session' ? $user->tokens()->getRelated()->getTable() : 'tx_settings';
        $name = $this->identityConnection();
        // Close the parent PDO before each fork. No child receives a live
        // parent transaction or socket; each native owner admits for itself.
        DB::disconnect($name);
        [$writer, $writerSocket] = $this->spawn(function ($socket) use ($kind, $user, $key, $owner, $domainTable, $mutationTable): void {
            $mysqlId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $authoritySeen = false;
            $domainLockAfterAuthority = false;
            $proof = null;
            DB::listen(function ($query) use ($socket, $owner, $domainTable, $mutationTable, $mysqlId,
                &$authoritySeen, &$domainLockAfterAuthority, &$proof): void {
                $sql = strtolower($query->sql);
                if (str_contains($sql, 'tx_authority_writer_fence') && str_contains($sql, 'lock in share mode')) {
                    $authoritySeen = true;
                }
                // MySQL versions may emit FOR SHARE for the same shared lock.
                if (str_contains($sql, 'tx_authority_writer_fence') && str_contains($sql, 'for share')) {
                    $authoritySeen = true;
                }
                if (str_contains($sql, $domainTable) && str_contains($sql, 'for update')) {
                    $domainLockAfterAuthority = $authoritySeen;
                }
                if (!preg_match('/\A(?:insert|update)\b/i', $sql) || !str_contains($sql, $mutationTable)) {
                    return;
                }
                $proof = app(AuthorityAdmissionRegistry::class)->current();
                $proof->assertValid();
                $this->send($socket, ['stage' => 'native_sql_pending', 'owner' => $proof->writer,
                    'epoch' => $proof->epoch, 'pid' => $proof->pid, 'mysql_id' => $mysqlId,
                    'own_pid' => $proof->pid === getmypid(), 'own_connection' => $proof->connection === DB::connection(),
                    'in_transaction' => DB::connection()->getPdo()->inTransaction(),
                    'authority_before_domain_lock' => $domainLockAfterAuthority, 'expected_owner' => $owner]);
                if ($this->receive($socket)['action'] === 'rollback') {
                    throw new \RuntimeException('IDENTITY_CONCURRENCY_SYNTHETIC_ROLLBACK');
                }
            });
            try {
                if ($kind === 'session') {
                    (new AuthService(User::findOrFail($user->id)))->generateAuthData();
                } else {
                    app(Setting::class)->set($key, 'committed');
                }
                $result = 'committed';
            } catch (\RuntimeException $error) {
                if ($error->getMessage() !== 'IDENTITY_CONCURRENCY_SYNTHETIC_ROLLBACK') throw $error;
                $result = 'rolled_back';
            }
            $expired = false;
            try {
                $proof->assertValid();
            } catch (AuthorityFenceException $error) {
                $expired = $error->getMessage() === 'AUTHORITY_ADMISSION_LIFETIME_INVALID';
            }
            $this->send($socket, ['result' => $result, 'proof_expired' => $expired,
                'in_transaction' => DB::connection()->getPdo()->inTransaction()]);
        });
        $controller = null;
        $controllerSocket = null;
        try {
            $pending = $this->receive($writerSocket);
            $this->assertSame('native_sql_pending', $pending['stage'] ?? null);
            $this->assertSame($owner, $pending['owner']);
            $this->assertSame('5', $pending['epoch']);
            foreach (['own_pid', 'own_connection', 'in_transaction', 'authority_before_domain_lock'] as $field) {
                $this->assertTrue($pending[$field], $field);
            }
            $this->assertSame($writer, $pending['pid']);
            DB::disconnect($name);
            [$controller, $controllerSocket] = $this->spawn(function ($socket): void {
                $mysqlId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
                $this->send($socket, ['stage' => 'prepare_attempt', 'pid' => getmypid(), 'mysql_id' => $mysqlId]);
                $this->send($socket, ['result' => $this->fence()->prepare('identity-independent-stop', '5')]);
            });
            $attempt = $this->receive($controllerSocket);
            $this->assertSame('prepare_attempt', $attempt['stage'] ?? null);
            $this->assertSame($controller, $attempt['pid']);
            $this->assertNotSame($pending['mysql_id'], $attempt['mysql_id']);
            $this->assertPrepareWaiting($attempt['mysql_id'], $controllerSocket);
            if ($mode === 'kill') {
                $this->assertTrue(posix_kill($writer, SIGKILL));
                pcntl_waitpid($writer, $status);
                $this->assertTrue(pcntl_wifsignaled($status));
                $this->assertSame(SIGKILL, pcntl_wtermsig($status));
                $writer = null;
            } else {
                $this->send($writerSocket, ['action' => $mode]);
                $finished = $this->receive($writerSocket);
                $this->assertSame($mode === 'commit' ? 'committed' : 'rolled_back', $finished['result'] ?? null);
                $this->assertTrue($finished['proof_expired']);
                $this->assertFalse($finished['in_transaction']);
                pcntl_waitpid($writer, $status);
                $writer = null;
            }
            $prepared = $this->receive($controllerSocket);
            $this->assertSame('6', $prepared['result']['authority_epoch'] ?? null);
            $this->assertSame('prepared', $prepared['result']['phase']);
            $this->assertFalse($prepared['result']['global_drain_complete']);
            pcntl_waitpid($controller, $status);
            $controller = null;
            $this->assertSame($kind === 'session' && $mode === 'commit' ? 1 : 0, $user->tokens()->count());
            $this->assertSame($kind === 'setting' && $mode === 'commit' ? 'committed' : 'original',
                SettingModel::where('name', $key)->value('value'));
            $this->assertSame(0, DB::table('tx_authority_postcommit_intent')->count());
            $this->assertSame(0, DB::table('tx_authority_postcommit_delivery')->count());
            $this->refused(fn () => (new AuthService($user->fresh()))->generateAuthData(), 'AUTHORITY_FENCE_STALE_EPOCH');
            $this->refused(fn () => app(Setting::class)->set($key, 'refused'), 'AUTHORITY_FENCE_STALE_EPOCH');
            // Updating a worker declaration cannot reopen the prepared singleton.
            config(['authority_writer_fence.worker_epoch' => '6']);
            $this->refused(fn () => (new AuthService($user->fresh()))->generateAuthData(), 'AUTHORITY_FENCE_PREPARED');
            $this->refused(fn () => app(Setting::class)->set($key, 'refused'), 'AUTHORITY_FENCE_PREPARED');
            $this->assertSame($kind === 'session' && $mode === 'commit' ? 1 : 0, $user->tokens()->count());
            $this->assertSame($kind === 'setting' && $mode === 'commit' ? 'committed' : 'original',
                SettingModel::where('name', $key)->value('value'));
        } finally {
            foreach ([$writer, $controller] as $pid) {
                if ($pid) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);
                }
            }
            fclose($writerSocket);
            if (is_resource($controllerSocket)) fclose($controllerSocket);
            DB::purge($name);
        }
    }

    private function assertPrepareWaiting(int $mysqlId, $socket): void
    {
        $deadline = microtime(true) + 5;
        $atSingleton = false;
        do {
            $row = DB::selectOne('SELECT INFO AS statement_text FROM information_schema.PROCESSLIST WHERE ID = ?', [$mysqlId]);
            $sql = strtolower((string) ($row->statement_text ?? ''));
            $atSingleton = str_contains($sql, 'tx_authority_writer_fence') && str_contains($sql, 'for update');
            if (!$atSingleton) usleep(10000);
        } while (!$atSingleton && microtime(true) < $deadline);
        $this->assertTrue($atSingleton, 'Independent prepare must be executing its singleton lock query.');
        $read = [$socket];
        $write = null;
        $except = null;
        $this->assertSame(0, stream_select($read, $write, $except, 0, 150000),
            'Prepare must wait while the native owner holds its admission.');
    }

    private function spawn(callable $task): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($pair[0]);
            DB::purge($this->identityConnection());
            try {
                $task($pair[1]);
            } catch (\Throwable $error) {
                // No SQL bindings, bearer token, setting value or raw exception
                // text may enter subprocess evidence.
                $this->send($pair[1], ['child_error' => get_class($error),
                    'code' => $error instanceof AuthorityFenceException ? $error->getMessage() : 'fixture_failure']);
            }
            fclose($pair[1]);
            exit(0);
        }
        fclose($pair[1]);
        return [$pid, $pair[0]];
    }

    private function send($socket, array $value): void
    {
        fwrite($socket, json_encode($value, JSON_THROW_ON_ERROR)."\n");
        fflush($socket);
    }

    private function receive($socket): array
    {
        stream_set_timeout($socket, 15);
        $line = fgets($socket);
        $this->assertIsString($line, 'Timed out waiting for an independent process.');
        return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }
}
