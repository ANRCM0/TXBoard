<?php

namespace Tests\Feature\Subscription;

use App\Domains\Billing\OrderIdempotency;
use App\Models\Order;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\NativeSubscriptionTestCase;
use Throwable;

/** Independent RR sessions; the native admission fixture does not serialize buyers. */
class PublishedOrderMysqlConcurrencyTest extends NativeSubscriptionTestCase
{
    public function test_waiting_new_purchase_sees_capacity_reserved_by_the_preceding_plan_owner(): void
    {
        $this->requireMysqlProcesses();
        $plan = $this->plan(['capacity_limit' => 1]);
        $offer = $this->publishedOffer($plan);
        $winner = $this->user();
        $waiting = $this->user();

        $order = $this->competeForLastPlace($winner->id, $waiting->id, $offer, $plan->id);

        $this->assertSame(Order::TYPE_NEW_PURCHASE, (int) $order->type);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, DB::table('tx_order_purchase_terms')->count());
        $this->assertSame(0, DB::table('tx_subscription')->count());
        $this->assertNull(DB::table('tx_subscription_slot')->where('user_id', $waiting->id)->value('subscription_id'));
    }

    public function test_waiting_plan_change_sees_another_buyers_uncommitted_upgrade_reservation(): void
    {
        $this->requireMysqlProcesses();
        $sourceOffer = $this->publishedOffer($this->plan());
        $targetPlan = $this->plan(['capacity_limit' => 1]);
        $targetOffer = $this->publishedOffer($targetPlan);
        $winner = $this->user();
        $waiting = $this->user();
        $now = time() - 60;
        foreach ([$winner, $waiting] as $user) {
            $initial = app(PublishedOrderService::class)->create($user->id, $sourceOffer, 'initial');
            DB::table('tx_order')->where('id', $initial->id)->update([
                'status' => Order::STATUS_PROCESSING, 'paid_at' => $now,
            ]);
            app(SubscriptionFulfillmentService::class)->fulfill($initial->id, $now);
        }
        $before = DB::table('tx_subscription')->orderBy('id')->get()->toArray();

        $order = $this->competeForLastPlace($winner->id, $waiting->id, $targetOffer, $targetPlan->id);

        $this->assertSame(Order::TYPE_UPGRADE, (int) $order->type);
        $this->assertSame(1, Order::query()->where('plan_id', $targetPlan->id)->count());
        $this->assertSame(3, DB::table('tx_order_purchase_terms')->count());
        $this->assertEquals($before, DB::table('tx_subscription')->orderBy('id')->get()->toArray());
    }

    private function competeForLastPlace(int $winnerId, int $waitingId, int $offerId, int $planId): Order
    {
        $this->prepareClaim($winnerId, $offerId, 'winner');
        $this->prepareClaim($waitingId, $offerId, 'waiting');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$parent, $child] = $pair;
        // Never fork with a live PDO: each process must own its own SQL session.
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::purge($name);
        }
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($parent);
            stream_set_timeout($child, 15);
            try {
                $this->configureSession();
                if ($this->readMessage($child) !== 'CREATE') {
                    throw new RuntimeException('CONCURRENCY_START_SIGNAL_MISSING');
                }
                $announced = false;
                DB::connection()->beforeExecuting(function (string $sql, array $bindings) use ($child, $planId, &$announced): void {
                    if (!$announced && str_contains(strtolower($sql), 'from `tx_plan`')
                        && str_contains(strtolower($sql), 'for update') && (int) ($bindings[0] ?? 0) === $planId) {
                        $announced = true;
                        $this->writeMessage($child, 'PLAN_LOCK');
                    }
                });
                $created = app(PublishedOrderService::class)->create($waitingId, $offerId, 'waiting');
                $result = ['order_id' => (int) $created->id, 'reached_plan_lock' => $announced];
            } catch (Throwable $error) {
                $result = ['error' => $error::class, 'message' => $error->getMessage()];
            }
            $this->writeMessage($child, json_encode($result, JSON_THROW_ON_ERROR));
            DB::disconnect();
            fclose($child);
            exit(0);
        }

        fclose($child);
        stream_set_timeout($parent, 15);
        $completed = false;
        $released = false;
        try {
            $this->configureSession();
            DB::connection()->beforeExecuting(function (string $sql, array $bindings) use ($parent, &$released): void {
                if ($released || !str_starts_with(strtolower($sql), 'update `idempotency_keys`')
                    || !in_array('succeeded', $bindings, true)) {
                    return;
                }
                $released = true;
                // The real first order and its frozen terms exist, but its transaction
                // still owns the Plan lock. The second session has to wait here.
                $this->writeMessage($parent, 'CREATE');
                $this->assertSame('PLAN_LOCK', $this->readMessage($parent));
                $read = [$parent];
                $write = [];
                $except = [];
                $this->assertSame(0, stream_select($read, $write, $except, 0, 250000),
                    'The second buyer completed while the first still owned the Plan lock.');
            });
            $order = app(PublishedOrderService::class)->create($winnerId, $offerId, 'winner');
            $this->assertTrue($released, 'The first order must remain uncommitted until the other buyer reaches its Plan lock.');
            $result = json_decode($this->readMessage($parent), true, 512, JSON_THROW_ON_ERROR);
            $completed = true;
            $this->assertSame('PUBLISHED_ORDER_CAPACITY_EXHAUSTED', $result['message'] ?? null,
                json_encode($result, JSON_THROW_ON_ERROR));
            $this->assertSame(0, Order::query()->where('user_id', $waitingId)->where('plan_id', $planId)->count());
            $this->assertSame('in_progress', DB::table('idempotency_keys')->where('user_id', $waitingId)
                ->where('key_hash', OrderIdempotency::keyHash($waitingId, OrderIdempotency::OPERATION_NATIVE_CREATE, 'waiting'))
                ->value('status'));
            $this->assertSame(1, Order::query()->where('plan_id', $planId)
                ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            if (!$completed) {
                posix_kill($pid, SIGKILL);
            }
            fclose($parent);
            pcntl_waitpid($pid, $status);
            // Remove callbacks before assertions or fixture teardown use another connection.
            DB::purge();
        }
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));

        return $order;
    }

    private function prepareClaim(int $userId, int $offerId, string $key): void
    {
        $now = time();
        DB::transaction(fn () => app(NativeSubscriptionStore::class)->lockAccount($userId, $now, self::INSTALLATION));
        // Existing slots and recoverable claims isolate Plan contention from unrelated
        // missing-row gap locks, without bypassing the production create transaction.
        DB::table('idempotency_keys')->insert([
            'user_id' => $userId, 'operation' => OrderIdempotency::OPERATION_NATIVE_CREATE,
            'key_hash' => OrderIdempotency::keyHash($userId, OrderIdempotency::OPERATION_NATIVE_CREATE, $key),
            'request_hash' => OrderIdempotency::requestHash([
                'schema' => PublishedOrderService::VERSION, 'offer_id' => (string) $offerId,
                'installation_id' => self::INSTALLATION,
            ]),
            'status' => 'in_progress', 'trade_no' => null, 'result_snapshot' => null,
            'expires_at' => $now + 86400, 'created_at' => $now - 7200, 'updated_at' => $now - 7200,
        ]);
    }

    private function configureSession(): void
    {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
    }

    private function writeMessage($socket, string $message): void
    {
        $line = $message."\n";
        if (fwrite($socket, $line) !== strlen($line)) {
            throw new RuntimeException('CONCURRENCY_SIGNAL_WRITE_FAILED');
        }
        fflush($socket);
    }

    private function readMessage($socket): string
    {
        $message = fgets($socket);
        if ($message === false) {
            throw new RuntimeException('CONCURRENCY_SIGNAL_READ_FAILED');
        }
        return rtrim($message, "\r\n");
    }

    private function requireMysqlProcesses(): void
    {
        if (getenv('CORE_NATIVE_TEST_MYSQL') !== '1' || DB::getDriverName() !== 'mysql'
            || !function_exists('pcntl_fork') || !function_exists('posix_kill')
            || !function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Requires the dedicated native MySQL fixture and independent POSIX processes.');
        }
    }
}
