<?php

namespace Tests\Feature\AuthorityMigration;

use App\Domains\AuthorityMigration\AuthorityAdmissionRegistry;
use App\Domains\AuthorityMigration\AuthorityFenceException;
use App\Domains\Support\TicketWorkflow;
use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\Plugin\HookManager;
use App\Services\TicketService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\AuthorityTicketFixture;
use Tests\TestCase;

/** Actual native owners serialize on current Ticket rows in independent MySQL processes. */
class AuthorityTicketCurrentRowTest extends TestCase
{
    use AuthorityTicketFixture;

    private bool $databaseConfigured = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->ticketMysql() || !function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Requires AUTHORITY_TICKET_MYSQL=1 and independent MySQL processes.');
        }
        $this->configureTicketDatabase();
        $this->databaseConfigured = true;
        config(['authority_writer_fence.enabled' => false, 'core_shadow.isolated_copy' => true,
            'cache.default' => 'array', 'cache.setting_store' => 'array',
            'legacy_capture_shadow.enabled' => false, 'legacy_observer_capture.enabled' => false,
            'legacy_admin_creation_shadow.enabled' => false, 'legacy_registration_creation_shadow.enabled' => false,
            'legacy_bootstrap_creation_shadow.enabled' => false, 'legacy_admin_deletion_shadow.enabled' => false]);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        admin_setting(['ticket_must_wait_reply' => 0]);
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

    public function test_admin_reply_waits_for_native_close_then_refuses_the_current_closed_ticket(): void
    {
        $this->ordering(true);
    }

    public function test_native_close_waits_for_user_reply_then_closes_its_committed_current_ticket(): void
    {
        $this->ordering(false);
    }

    private function ordering(bool $closeFirst): void
    {
        $user = $this->user();
        $admin = $this->user(['is_admin' => 1]);
        $ticket = $this->ticket($user);
        $name = $this->ticketConnection();
        DB::disconnect($name);
        [$holder, $holderSocket] = $this->spawn(function ($socket) use ($closeFirst, $user, $ticket): void {
            $mysqlId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $paused = false;
            DB::listen(function ($query) use ($socket, $mysqlId, $closeFirst, &$paused): void {
                $sql = strtolower($query->sql);
                $isPausePoint = $closeFirst
                    ? preg_match('/\Aupdate\b/i', $sql) && str_contains($sql, 'tx_ticket')
                    : preg_match('/\Ainsert\b/i', $sql) && str_contains($sql, 'tx_ticket_message');
                if ($paused || !$isPausePoint) return;
                $paused = true;
                $proof = app(AuthorityAdmissionRegistry::class)->current();
                $proof->assertValid();
                $this->send($socket, ['stage' => 'native_sql_pending', 'owner' => $proof->writer,
                    'mysql_id' => $mysqlId, 'pid' => getmypid(), 'proof_pid' => $proof->pid,
                    'own_connection' => $proof->connection === DB::connection(),
                    'in_transaction' => DB::connection()->getPdo()->inTransaction()]);
                $this->assertSame('commit', $this->receive($socket)['action']);
            });
            $workflow = app(TicketWorkflow::class);
            if ($closeFirst) $workflow->close($user->id, $ticket->id);
            else $workflow->reply($user->id, $ticket->id, 'Synthetic ordered reply');
            $this->send($socket, ['result' => 'committed', 'paused' => $paused,
                'in_transaction' => DB::connection()->getPdo()->inTransaction()]);
        });
        $waiter = null;
        $waiterSocket = null;
        try {
            $pending = $this->receive($holderSocket);
            $this->assertSame('native_sql_pending', $pending['stage'] ?? null);
            $this->assertSame($closeFirst ? 'user.ticket.close' : 'user.ticket.reply', $pending['owner']);
            $this->assertSame($holder, $pending['pid']);
            $this->assertSame($holder, $pending['proof_pid']);
            $this->assertTrue($pending['own_connection']);
            $this->assertTrue($pending['in_transaction']);
            DB::disconnect($name);
            [$waiter, $waiterSocket] = $this->spawn(function ($socket) use ($closeFirst, $user, $admin, $ticket): void {
                $mysqlId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
                $hookCalled = false;
                HookManager::register('ticket.reply.admin.after', function () use (&$hookCalled): void { $hookCalled = true; });
                $this->send($socket, ['stage' => 'native_attempt', 'mysql_id' => $mysqlId, 'pid' => getmypid()]);
                $status = 200;
                try {
                    if ($closeFirst) app(TicketService::class)->replyByAdmin($ticket->id, 'Synthetic refused reply', $admin->id);
                    else app(TicketWorkflow::class)->close($user->id, $ticket->id);
                } catch (HttpExceptionInterface $error) {
                    $status = $error->getStatusCode();
                }
                Bus::assertNotDispatched(SendEmailJob::class);
                $this->send($socket, ['result' => 'finished', 'status' => $status, 'hook_called' => $hookCalled,
                    'in_transaction' => DB::connection()->getPdo()->inTransaction()]);
            });
            $attempt = $this->receive($waiterSocket);
            $this->assertSame('native_attempt', $attempt['stage'] ?? null);
            $this->assertSame($waiter, $attempt['pid']);
            $this->assertNotSame($pending['mysql_id'], $attempt['mysql_id']);
            $this->assertTicketWaiting($attempt['mysql_id'], $waiterSocket);
            $this->send($holderSocket, ['action' => 'commit']);
            $committed = $this->receive($holderSocket);
            $this->assertSame('committed', $committed['result'] ?? null);
            $this->assertTrue($committed['paused']);
            $this->assertFalse($committed['in_transaction']);
            $this->reap($holder);
            $holder = null;
            $finished = $this->receive($waiterSocket);
            $this->assertSame('finished', $finished['result'] ?? null);
            $this->assertSame($closeFirst ? 409 : 200, $finished['status']);
            $this->assertFalse($finished['hook_called']);
            $this->assertFalse($finished['in_transaction']);
            $this->reap($waiter);
            $waiter = null;
            DB::purge($name);
            $current = Ticket::findOrFail($ticket->id);
            $this->assertSame(Ticket::STATUS_CLOSED, (int) $current->status);
            $this->assertSame(Ticket::REPLY_STATUS_WAITING, (int) $current->reply_status);
            $this->assertSame((int) $user->id, (int) $current->last_reply_user_id);
            $this->assertSame($closeFirst ? 1 : 2, TicketMessage::where('ticket_id', $ticket->id)->count());
            if (!$closeFirst) {
                $this->assertSame('Synthetic ordered reply', TicketMessage::where('ticket_id', $ticket->id)
                    ->orderByDesc('id')->value('message'));
            }
        } finally {
            foreach ([$holder, $waiter] as $pid) {
                if ($pid) { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); }
            }
            fclose($holderSocket);
            if (is_resource($waiterSocket)) fclose($waiterSocket);
            DB::purge($name);
        }
    }

    private function assertTicketWaiting(int $mysqlId, $socket): void
    {
        $deadline = microtime(true) + 5;
        $atTicket = false;
        do {
            $row = DB::selectOne('SELECT INFO AS statement_text FROM information_schema.PROCESSLIST WHERE ID = ?', [$mysqlId]);
            $sql = strtolower((string) ($row->statement_text ?? ''));
            $atTicket = str_contains($sql, 'tx_ticket') && str_contains($sql, 'for update');
            if (!$atTicket) usleep(10000);
        } while (!$atTicket && microtime(true) < $deadline);
        $this->assertTrue($atTicket, 'Independent native owner must be executing its current Ticket lock query.');
        $read = [$socket];
        $write = null;
        $except = null;
        $this->assertSame(0, stream_select($read, $write, $except, 0, 150000),
            'The second native owner must wait for the first Ticket transaction to commit.');
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
                // Only fixed phase/status observations leave the child; no SQL bindings or raw exception payloads.
                $this->send($pair[1], ['child_error' => get_class($error),
                    'code' => $error instanceof AuthorityFenceException ? $error->getMessage() : 'fixture_failure']);
            }
            fclose($pair[1]);
            exit(0);
        }
        fclose($pair[1]);
        return [$pid, $pair[0]];
    }

    private function reap(int $pid): void
    {
        $this->assertSame($pid, pcntl_waitpid($pid, $status));
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
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
        $this->assertIsString($line, 'Timed out waiting for an independent native owner.');
        return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }
}
