<?php

namespace Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

trait NativeMysqlWorkflowRace
{
    private function race(array $operations, bool $pinOlderSnapshots = false): array
    {
        $directory = sys_get_temp_dir().'/txboard-native-workflow-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];
        foreach (array_keys(DB::getConnections()) as $name) { DB::purge($name); }
        try {
            foreach ($operations as $index => $operation) {
                $pid = pcntl_fork();
                if ($pid < 0) { throw new \RuntimeException('Financial workflow concurrency fork failed.'); }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                        DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
                        if ($pinOlderSnapshots) {
                            $pinned = false;
                            DB::listen(function (QueryExecuted $query) use ($directory, $operations, $index, &$pinned): void {
                                if ($pinned || DB::transactionLevel() === 0
                                    || !str_contains($query->sql, 'tx_authority_writer_fence')) { return; }
                                $pinned = true;
                                if ($index === 0) {
                                    foreach (array_keys($operations) as $follower) {
                                        if ($follower !== 0) { $this->awaitFile($directory.'/snapshot-'.$follower); }
                                    }
                                } else {
                                    // Freeze an ordinary RR snapshot before the owning user lock.
                                    DB::table('tx_money_journal')->limit(1)->get(['id']);
                                    touch($directory.'/snapshot-'.$index);
                                    $this->awaitFile($directory.'/committed');
                                }
                            });
                        }
                        $deadline = microtime(true) + 20;
                        while (!is_file($directory.'/start') && microtime(true) < $deadline) { usleep(1000); }
                        if (!is_file($directory.'/start')) { throw new \RuntimeException('Financial workflow barrier timeout.'); }
                        file_put_contents($directory.'/'.$index, json_encode($operation(), JSON_THROW_ON_ERROR));
                        if ($pinOlderSnapshots && $index === 0) { touch($directory.'/committed'); }
                        DB::disconnect();
                        exit(0);
                    } catch (\Throwable $error) {
                        file_put_contents($directory.'/'.$index, json_encode(['unexpected_error' => $error->getMessage()]));
                        exit(2);
                    }
                }
                $children[] = $pid;
            }
            touch($directory.'/start');
            $statuses = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $statuses[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
            }
            $children = [];
            DB::purge();
            $results = [];
            foreach ($operations as $index => $_operation) {
                $result = json_decode(file_get_contents($directory.'/'.$index), true, 32, JSON_THROW_ON_ERROR);
                $this->assertArrayNotHasKey('unexpected_error', $result, json_encode($result, JSON_THROW_ON_ERROR));
                $results[] = $result;
            }
            $this->assertSame(array_fill(0, count($operations), 0), $statuses);
            return $results;
        } finally {
            foreach ($children as $pid) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
            foreach (glob($directory.'/*') ?: [] as $path) { unlink($path); }
            rmdir($directory);
            DB::purge();
        }
    }

    private function awaitFile(string $path): void
    {
        $deadline = microtime(true) + 20;
        while (!is_file($path) && microtime(true) < $deadline) { usleep(1000); }
        if (!is_file($path)) { throw new \RuntimeException('Financial workflow snapshot barrier timeout.'); }
    }

    private function requireMysqlProcesses(): void
    {
        if (getenv('CORE_NATIVE_TEST_MYSQL') !== '1' || DB::getDriverName() !== 'mysql'
            || !function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Requires dedicated native MySQL and independent POSIX sessions.');
        }
    }
}
