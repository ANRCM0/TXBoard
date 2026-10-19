<?php

namespace Tests\Feature\Support;

use App\Domains\Support\NativeTicketWorkflow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\NativeRuntimeTestCase;
use Tests\Support\NativeWorkflowActors;

final class NativeTicketWorkflowMysqlConcurrencyTest extends NativeRuntimeTestCase
{
    use NativeWorkflowActors;

    public function test_two_administrators_cannot_both_claim_the_same_ticket_version(): void
    {
        if (getenv('CORE_NATIVE_TEST_MYSQL') !== '1' || DB::getDriverName() !== 'mysql'
            || !function_exists('pcntl_fork') || !function_exists('posix_kill') || !function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Requires dedicated MySQL and independent POSIX sessions.');
        }
        $this->activate();
        $owner = $this->workflowActor(); $this->authenticateWorkflowActor($owner);
        $ticket = $this->invokeWorkflow(fn () => app(NativeTicketWorkflow::class)->create($owner->id,
            ['subject' => 'Concurrent claim', 'message' => 'Owner message', 'level' => 1], $this->workflowKey()));
        $first = $this->workflowActor(true, ['support.tickets.read', 'support.tickets.write']);
        $second = $this->workflowActor(true, ['support.tickets.read', 'support.tickets.write']);
        $secondToken = $this->authenticateWorkflowActor($second);
        $this->authenticateWorkflowActor($first);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$parent, $child] = $pair;
        foreach (array_keys(DB::getConnections()) as $connection) DB::purge($connection);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        $input = ['ticket_id' => $ticket['ticket_id'], 'expected_version' => '1'];
        if ($pid === 0) {
            fclose($parent); stream_set_timeout($child, 15);
            try {
                DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
                Auth::forgetGuards(); Auth::guard('sanctum')->setUser($second->withAccessToken($secondToken));
                if (trim((string) fgets($child)) !== 'START') throw new \RuntimeException('START_SIGNAL_MISSING');
                $announced = false;
                DB::connection()->beforeExecuting(function (string $sql) use ($child, &$announced): void {
                    if (!$announced && str_contains(strtolower($sql), 'from `tx_ticket`') && str_contains(strtolower($sql), 'for update')) {
                        $announced = true; fwrite($child, "LOCK_QUERY\n"); fflush($child);
                    }
                });
                try {
                    $this->invokeWorkflow(fn () => app(NativeTicketWorkflow::class)->claim($second->id, $input, $this->workflowKey()));
                    $status = 200;
                } catch (HttpExceptionInterface $error) { $status = $error->getStatusCode(); }
                fwrite($child, json_encode(['status' => $status, 'announced' => $announced], JSON_THROW_ON_ERROR)."\n");
                fflush($child); DB::disconnect(); fclose($child); exit(0);
            } catch (\Throwable) {
                fwrite($child, "CHILD_FAILED\n"); fflush($child); fclose($child); exit(1);
            }
        }
        fclose($child); stream_set_timeout($parent, 15); $finished = false; $announced = false;
        try {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
            DB::connection()->beforeExecuting(function (string $sql) use ($parent, &$announced): void {
                if (!$announced && str_starts_with(strtolower($sql), 'update `tx_ticket`')) {
                    $announced = true; fwrite($parent, "START\n"); fflush($parent);
                    $this->assertSame('LOCK_QUERY', trim((string) fgets($parent)));
                    $read = [$parent]; $write = $except = [];
                    $this->assertSame(0, stream_select($read, $write, $except, 0, 200000), 'Competing claim completed while the ticket lock was held');
                }
            });
            $claim = $this->invokeWorkflow(fn () => app(NativeTicketWorkflow::class)->claim($first->id, $input, $this->workflowKey()));
            $result = json_decode((string) fgets($parent), true, 16, JSON_THROW_ON_ERROR);
            $this->assertTrue($announced); $this->assertTrue($result['announced']);
            $this->assertSame(409, $result['status']);
            $this->assertSame((string) $first->id, $claim['assigned_admin_id']);
            $finished = true;
        } finally {
            if (!$finished) posix_kill($pid, SIGKILL);
            fclose($parent); pcntl_waitpid($pid, $status);
            DB::purge();
        }
        $this->assertTrue(pcntl_wifexited($status)); $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertSame((int) $first->id, (int) DB::table('tx_ticket')->value('assigned_admin_id'));
        $this->assertSame(2, (int) DB::table('tx_ticket')->value('row_version'));
        $this->assertSame(2, DB::table('tx_support_request')->count());
        $this->assertSame(2, DB::table('tx_support_audit')->count());
        $this->assertSame(1, DB::table('tx_module_event')->where('kind', 'support.ticket.claimed.v1')->count());
    }
}
