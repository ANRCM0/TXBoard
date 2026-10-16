<?php

namespace Tests\Feature\AuthorityMigration;

use App\Domains\AuthorityMigration\AuthorityAdmissionRegistry;
use App\Domains\AuthorityMigration\AuthorityFenceException;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthorityTicketFixture;
use Tests\TestCase;

/** Real native phases, independent PDOs and prepare lock waits on the guarded dedicated database. */
class AuthorityTicketConcurrencyTest extends TestCase
{
    use AuthorityTicketFixture;

    private bool $databaseConfigured = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->ticketMysql() || !function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Requires AUTHORITY_TICKET_MYSQL=1, independent MySQL processes and SIGKILL.');
        }
        // The shared fixture validates the exact database, account and loopback
        // endpoint before any destructive setup or cleanup.
        $this->configureTicketDatabase();
        $this->databaseConfigured = true;
        config(['authority_writer_fence.enabled' => false, 'core_shadow.isolated_copy' => true,
            'cache.default' => 'array', 'cache.setting_store' => 'array']);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        Bus::fake();
        HookManager::reset();
        config(['authority_writer_fence' => ['enabled' => true, 'worker_epoch' => '1', 'worker_role' => 'legacy']]);
        $this->tickets();
    }

    protected function tearDown(): void
    {
        HookManager::reset();
        if ($this->databaseConfigured) {
            $this->clearTicketDatabase();
            DB::purge($this->ticketConnection());
        }
        parent::tearDown();
    }

    public function test_native_ticket_create_commit_releases_prepare7_only_after_both_rows_commit(): void
    {
        $this->ordering('commit');
    }

    public function test_native_ticket_create_rollback_unblocks_prepare7_and_rolls_back_both_rows(): void
    {
        $this->ordering('rollback');
    }

    public function test_sigkill_before_native_ticket_commit_rolls_back_and_unblocks_prepare7(): void
    {
        $this->ordering('kill');
    }

    private function ordering(string $mode): void
    {
        $user = $this->user();
        $name = $this->ticketConnection();
        DB::disconnect($name);
        [$writer, $writerSocket] = $this->spawn(function ($socket) use ($user): void {
            $mysqlId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $authoritySeen = false;
            $domainLockAfterAuthority = false;
            $proof = null;
            DB::listen(function ($query) use ($socket, $mysqlId, &$authoritySeen, &$domainLockAfterAuthority, &$proof): void {
                $sql = strtolower($query->sql);
                if (str_contains($sql, 'tx_authority_writer_fence')
                    && (str_contains($sql, 'lock in share mode') || str_contains($sql, 'for share'))) $authoritySeen = true;
                if (str_contains($sql, 'tx_ticket') && str_contains($sql, 'for update')) $domainLockAfterAuthority = $authoritySeen;
                if (!preg_match('/\Ainsert\b/i', $sql) || !str_contains($sql, 'tx_ticket_message')) return;
                $proof = app(AuthorityAdmissionRegistry::class)->current();
                $proof->assertValid();
                $this->send($socket, ['stage' => 'native_sql_pending', 'owner' => $proof->writer,
                    'epoch' => $proof->epoch, 'pid' => $proof->pid, 'mysql_id' => $mysqlId,
                    'own_pid' => $proof->pid === getmypid(), 'own_connection' => $proof->connection === DB::connection(),
                    'in_transaction' => DB::connection()->getPdo()->inTransaction(),
                    'authority_before_domain_lock' => $domainLockAfterAuthority]);
                if ($this->receive($socket)['action'] === 'rollback') throw new \RuntimeException('TICKET_CONCURRENCY_SYNTHETIC_ROLLBACK');
            });
            try {
                app(\App\Domains\Support\TicketWorkflow::class)->create($user->id, 'Synthetic concurrent fixture', 1, 'Initial');
                $result = 'committed';
            } catch (\RuntimeException $error) {
                if ($error->getMessage() !== 'TICKET_CONCURRENCY_SYNTHETIC_ROLLBACK') throw $error;
                $result = 'rolled_back';
            }
            $expired = false;
            try { $proof->assertValid(); }
            catch (AuthorityFenceException $error) { $expired = $error->getMessage() === 'AUTHORITY_ADMISSION_LIFETIME_INVALID'; }
            $this->send($socket, ['result' => $result, 'proof_expired' => $expired,
                'in_transaction' => DB::connection()->getPdo()->inTransaction()]);
        });
        $controller = null;
        $controllerSocket = null;
        try {
            $pending = $this->receive($writerSocket);
            $this->assertSame('native_sql_pending', $pending['stage'] ?? null);
            $this->assertSame('user.ticket.create', $pending['owner']);
            $this->assertSame('6', $pending['epoch']);
            foreach (['own_pid', 'own_connection', 'in_transaction', 'authority_before_domain_lock'] as $field) $this->assertTrue($pending[$field], $field);
            $this->assertSame($writer, $pending['pid']);
            DB::disconnect($name);
            [$controller, $controllerSocket] = $this->spawn(function ($socket): void {
                $mysqlId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
                $this->send($socket, ['stage' => 'prepare_attempt', 'pid' => getmypid(), 'mysql_id' => $mysqlId]);
                $this->send($socket, ['result' => $this->fence()->prepare('ticket-independent-stop', '6')]);
            });
            $attempt = $this->receive($controllerSocket);
            $this->assertSame('prepare_attempt', $attempt['stage'] ?? null);
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
            $this->assertSame('7', $prepared['result']['authority_epoch'] ?? null);
            $this->assertSame('prepared', $prepared['result']['phase']);
            $this->assertFalse($prepared['result']['global_drain_complete']);
            pcntl_waitpid($controller, $status);
            $controller = null;
            $this->assertSame($mode === 'commit' ? 1 : 0, \App\Models\Ticket::count());
            $this->assertSame($mode === 'commit' ? 1 : 0, \App\Models\TicketMessage::count());
            $this->refused(fn () => app(\App\Domains\Support\TicketWorkflow::class)->create($user->id, 'Refused', 1, 'Initial'), 'AUTHORITY_FENCE_STALE_EPOCH');
            config(['authority_writer_fence.worker_epoch' => '7']);
            $this->refused(fn () => app(\App\Domains\Support\TicketWorkflow::class)->create($user->id, 'Refused', 1, 'Initial'), 'AUTHORITY_FENCE_PREPARED');
        } finally {
            foreach ([$writer, $controller] as $pid) {
                if ($pid) { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); }
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
            DB::purge($this->ticketConnection());
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
