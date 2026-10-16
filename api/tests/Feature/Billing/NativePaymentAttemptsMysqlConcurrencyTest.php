<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\BillingError;
use App\Domains\Billing\NativePaymentAttempts;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativePaymentTestCase;

/** Independent SQL sessions exercise permanent receipt identity, not an in-process fake lock. */
final class NativePaymentAttemptsMysqlConcurrencyTest extends NativePaymentTestCase
{
    public function test_simultaneous_identical_notifications_commit_one_immutable_receipt(): void
    {
        $this->requireMysqlProcesses();
        [$user, $order] = $this->purchase();
        $attempt = app(NativePaymentAttempts::class)->prepare(
            $user->id, $order->id, $this->payment->id, 'merchant-1', 'receipt-replay');
        $fact = $this->fact($attempt);
        $work = fn (): array => app(NativePaymentAttempts::class)->recordVerifiedReceipt(
            $user->id, (int) $attempt['attempt_id'], $fact);
        $results = $this->race([$work, $work, $work, $work]);
        $this->assertCount(1, array_unique(array_column($results, 'receipt_id')));
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame('succeeded', DB::table('tx_payment_attempt')->where('id', $attempt['attempt_id'])->value('status'));
    }

    public function test_competing_owners_and_duplicate_channel_registrations_cannot_reuse_a_provider_transaction(): void
    {
        $this->requireMysqlProcesses();
        [$firstUser, $firstOrder] = $this->purchase();
        [$otherUser, $otherOrder] = $this->purchase();
        $duplicate = Payment::query()->create([
            'uuid' => bin2hex(random_bytes(16)), 'payment' => 'native-test', 'name' => 'Duplicate registration',
            'config' => [], 'enable' => true,
        ]);
        $first = app(NativePaymentAttempts::class)->prepare(
            $firstUser->id, $firstOrder->id, $this->payment->id, 'merchant-1', 'race-first');
        $other = app(NativePaymentAttempts::class)->prepare(
            $otherUser->id, $otherOrder->id, $duplicate->id, 'merchant-1', 'race-other');
        $work = function (int $userId, array $attempt): array {
            try {
                $receipt = app(NativePaymentAttempts::class)->recordVerifiedReceipt(
                    $userId, (int) $attempt['attempt_id'], $this->fact($attempt));
                return ['accepted' => true, 'receipt_id' => $receipt['receipt_id']];
            } catch (BillingError $error) {
                return ['accepted' => false, 'code' => $error->errorCode];
            }
        };
        $results = $this->race([
            fn (): array => $work($firstUser->id, $first),
            fn (): array => $work($otherUser->id, $other),
        ]);
        $this->assertCount(1, array_filter($results, static fn (array $result): bool => $result['accepted']));
        $rejected = array_values(array_filter($results, static fn (array $result): bool => !$result['accepted']));
        $this->assertSame('PAYMENT_RECEIPT_IDENTITY_CONFLICT', $rejected[0]['code']);
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame(1, DB::table('tx_payment_attempt')->where('status', 'succeeded')->count());
        $this->assertSame(1, DB::table('tx_payment_attempt')->where('status', 'prepared')->count());
        $this->assertSame(0, DB::table('tx_money_journal')->count());
    }

    private function race(array $operations): array
    {
        $directory = sys_get_temp_dir().'/txboard-native-payment-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::purge($name);
        }
        try {
            foreach ($operations as $index => $operation) {
                $pid = pcntl_fork();
                if ($pid < 0) {
                    throw new \RuntimeException('Payment concurrency fork failed.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                        DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
                        $deadline = microtime(true) + 20;
                        while (!is_file($directory.'/start') && microtime(true) < $deadline) {
                            usleep(1000);
                        }
                        if (!is_file($directory.'/start')) {
                            throw new \RuntimeException('Payment concurrency barrier timeout.');
                        }
                        file_put_contents($directory.'/'.$index, json_encode($operation(), JSON_THROW_ON_ERROR));
                        DB::disconnect();
                        exit(0);
                    } catch (\Throwable $error) {
                        file_put_contents($directory.'/'.$index, json_encode(['error' => $error->getMessage()]));
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
                $this->assertArrayNotHasKey('error', $result, json_encode($result, JSON_THROW_ON_ERROR));
                $results[] = $result;
            }
            $this->assertSame(array_fill(0, count($operations), 0), $statuses);
            return $results;
        } finally {
            foreach ($children as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            foreach (glob($directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
            DB::purge();
        }
    }

    private function requireMysqlProcesses(): void
    {
        if (getenv('CORE_NATIVE_TEST_MYSQL') !== '1' || DB::getDriverName() !== 'mysql'
            || !function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Requires the dedicated native MySQL database and independent POSIX processes.');
        }
    }
}
