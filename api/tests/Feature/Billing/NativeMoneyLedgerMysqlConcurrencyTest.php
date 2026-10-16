<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\NativeMoneyLedger;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\NativeRuntimeTestCase;
use Throwable;

/** Two real RR sessions contend on the first shared system account creation. */
final class NativeMoneyLedgerMysqlConcurrencyTest extends NativeRuntimeTestCase
{
    public function test_distinct_users_first_shared_clearing_account_race_preserves_both_journals(): void
    {
        if (getenv('CORE_NATIVE_TEST_MYSQL') !== '1' || DB::getDriverName() !== 'mysql'
            || !function_exists('pcntl_fork') || !function_exists('posix_kill')
            || !function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Requires dedicated MySQL and independent POSIX sessions.');
        }
        $this->activate();
        [, $first] = app(NativeIdentityService::class)->create('money-first@example.test', 'runtime-password');
        [, $second] = app(NativeIdentityService::class)->create('money-second@example.test', 'runtime-password');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$parent, $child] = $pair;
        foreach (array_keys(DB::getConnections()) as $name) { DB::purge($name); }
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($parent);
            stream_set_timeout($child, 15);
            try {
                $this->session();
                if ($this->read($child) !== 'POST') { throw new RuntimeException('START_SIGNAL_MISSING'); }
                $announced = false;
                DB::connection()->beforeExecuting(function (string $sql) use ($child, &$announced): void {
                    if (!$announced && str_contains(strtolower($sql), 'insert ignore into `tx_money_account`')) {
                        $announced = true;
                        $this->send($child, 'SYSTEM_INSERT');
                    }
                });
                $journal = app(NativeMoneyLedger::class)->post($second->id, $this->request($second->id, '200'));
                $result = ['journal_id' => $journal['journal_id'], 'system_insert' => $announced];
            } catch (Throwable $error) {
                $result = ['error' => $error::class, 'message' => $error->getMessage()];
            }
            $this->send($child, json_encode($result, JSON_THROW_ON_ERROR));
            DB::disconnect();
            fclose($child);
            exit(0);
        }
        fclose($child);
        stream_set_timeout($parent, 15);
        $finished = false;
        $released = false;
        try {
            $this->session();
            DB::connection()->beforeExecuting(function (string $sql) use ($parent, &$released): void {
                if ($released || !str_contains(strtolower($sql), 'insert into `tx_money_journal`')) { return; }
                $released = true;
                $this->send($parent, 'POST');
                $this->assertSame('SYSTEM_INSERT', $this->read($parent));
                $read = [$parent];
                $write = [];
                $except = [];
                $this->assertSame(0, stream_select($read, $write, $except, 0, 250000),
                    'Second user must wait for the shared clearing account owned by the uncommitted first journal.');
            });
            app(NativeMoneyLedger::class)->post($first->id, $this->request($first->id, '100'));
            $this->assertTrue($released);
            $result = json_decode($this->read($parent), true, 512, JSON_THROW_ON_ERROR);
            $finished = true;
            $this->assertArrayNotHasKey('error', $result, json_encode($result, JSON_THROW_ON_ERROR));
            $this->assertTrue($result['system_insert']);
            $this->assertSame(2, DB::table('tx_money_journal')->count());
            $this->assertSame(4, DB::table('tx_money_posting')->count());
            $this->assertSame(1, DB::table('tx_money_account')->where('owner_id', 0)->count());
            $this->assertSame('300', (string) DB::table('tx_money_account')->where('owner_id', 0)->value('balance_minor'));
            $this->assertSame('100', (string) DB::table('tx_money_account')->where('owner_id', $first->id)->value('balance_minor'));
            $this->assertSame('200', (string) DB::table('tx_money_account')->where('owner_id', $second->id)->value('balance_minor'));
        } finally {
            if (DB::transactionLevel() > 0) { DB::rollBack(0); }
            if (!$finished) { posix_kill($pid, SIGKILL); }
            fclose($parent);
            pcntl_waitpid($pid, $status);
            DB::purge();
        }
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    private function session(): void
    {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
    }

    private function request(int $userId, string $amount): array
    {
        return ['business_type' => 'wallet_credit', 'business_ref' => 'verified-internal-source',
            'idempotency_key' => 'concurrent-credit', 'currency' => 'USD', 'occurred_at' => 1700000000,
            'postings' => [
                ['owner_id' => 0, 'purpose' => 'provider_clearing', 'side' => 'debit', 'amount_minor' => $amount],
                ['owner_id' => $userId, 'purpose' => 'wallet_available', 'side' => 'credit', 'amount_minor' => $amount],
            ]];
    }

    private function send($socket, string $message): void
    {
        $line = $message."\n";
        if (fwrite($socket, $line) !== strlen($line)) { throw new RuntimeException('SIGNAL_WRITE_FAILED'); }
        fflush($socket);
    }

    private function read($socket): string
    {
        $line = fgets($socket);
        if ($line === false) { throw new RuntimeException('SIGNAL_READ_FAILED'); }
        return rtrim($line, "\r\n");
    }
}
