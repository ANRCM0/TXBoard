<?php

namespace Tests\Feature\Billing;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Real MySQL integration check, not a SQLite / mocked-lock simulation.
 * Ten independent processes release from the same barrier and make 100
 * attempts total. CI keeps this separate from RefreshDatabase transactions.
 */
class OrderIdempotencyMysqlConcurrencyTest extends TestCase
{
    public function test_concurrent_workers_serialize_one_hundred_replays_to_one_order(): void
    {
        if (DB::getDriverName() !== 'mysql' || !function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires MySQL and pcntl');
        }

        $suffix = bin2hex(random_bytes(5));
        $user = User::create([
            'email' => 'idempotency-stress-'.$suffix.'@example.test',
            'password' => 'test-hash', 'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'token' => bin2hex(random_bytes(16)), 'balance' => 0, 'banned' => 0,
        ]);
        $plan = Plan::create([
            'name' => 'Idempotency stress '.$suffix, 'group_id' => 1,
            'show' => true, 'sell' => true, 'renew' => true,
            'capacity_limit' => null, 'transfer_enable' => 2,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'prices' => [Plan::PERIOD_MONTHLY => 10],
        ]);
        $dir = sys_get_temp_dir().'/txboard-idempotency-stress-'.$suffix;
        mkdir($dir, 0700, true);
        $children = [];
        $workerCount = 10;
        $iterations = 10;

        try {
            for ($worker = 0; $worker < $workerCount; $worker++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Could not fork MySQL concurrency worker');
                }
                if ($pid === 0) {
                    try {
                        // A fork cannot safely reuse a parent MySQL socket.
                        DB::disconnect('mysql');
                        DB::purge('mysql');
                        $deadline = microtime(true) + 15;
                        while (!file_exists($dir.'/go') && microtime(true) < $deadline) {
                            usleep(10000);
                        }
                        if (!file_exists($dir.'/go')) {
                            throw new \RuntimeException('Worker barrier timed out');
                        }
                        $trades = [];
                        for ($i = 0; $i < $iterations; $i++) {
                            $order = OrderService::createFromRequest(
                                User::query()->findOrFail($user->id),
                                Plan::query()->findOrFail($plan->id),
                                Plan::PERIOD_MONTHLY,
                                null,
                                'stress-'.$suffix
                            );
                            $trades[] = $order->trade_no;
                        }
                        file_put_contents($dir.'/result-'.$worker,
                            json_encode(['trades' => $trades], JSON_THROW_ON_ERROR));
                        exit(0);
                    } catch (\Throwable $exception) {
                        file_put_contents($dir.'/result-'.$worker,
                            json_encode(['error' => $exception->getMessage()]));
                        exit(2);
                    }
                }
                $children[] = $pid;
            }
            // All children are forked before any of them may claim the key.
            touch($dir.'/go');
            $statuses = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $statuses[] = pcntl_wexitstatus($status);
            }
            $children = [];
            $trades = [];
            foreach (range(0, $workerCount - 1) as $worker) {
                $data = json_decode((string) file_get_contents($dir.'/result-'.$worker), true);
                $this->assertArrayNotHasKey('error', $data ?? [],
                    json_encode($data));
                $trades = array_merge($trades, $data['trades'] ?? []);
            }
            $this->assertSame(array_fill(0, $workerCount, 0), $statuses);
            $this->assertCount(100, $trades);
            $this->assertCount(1, array_unique($trades));
            $this->assertSame(1, Order::query()->where('user_id', $user->id)->count());
            $this->assertSame(1, DB::table('idempotency_keys')
                ->where('user_id', $user->id)->count());
            $this->assertSame('succeeded', DB::table('idempotency_keys')
                ->where('user_id', $user->id)->value('status'));
        } finally {
            foreach ($children as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            foreach (glob($dir.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
            DB::table('idempotency_keys')->where('user_id', $user->id)->delete();
            DB::table('tx_order')->where('user_id', $user->id)->delete();
            $user->delete();
            $plan->delete();
        }
    }
}
