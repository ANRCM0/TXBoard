<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\NativeBillingRecovery;
use App\Domains\Billing\NativePaymentAttempts;
use App\Domains\Billing\NativePaymentSettlement;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativePaymentTestCase;

final class NativeBillingRecoveryTest extends NativePaymentTestCase
{
    public function test_paid_receipt_survives_missing_queue_and_native_worker_fulfills_the_real_subscription_once(): void
    {
        [$user, $order, $settlement] = $this->paid();
        $this->assertSame(0, DB::table('tx_subscription')->count());
        $this->assertSame('pending', DB::table('tx_billing_work')->value('status'));
        $this->assertSame(0, Artisan::call('core:native-work', ['--limit' => 1]));
        $output = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('completed', $output['billing'][$settlement['work_id']]['status']);
        $this->assertSame(1, DB::table('tx_subscription')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('tx_subscription_operation')->where('order_id', $order->id)->count());
        $subscriptionId = DB::table('tx_subscription')->where('user_id', $user->id)->value('id');
        $this->assertSame('active', DB::table('tx_access_subject')->where('subscription_id', $subscriptionId)->value('status'));
        $this->assertSame('completed', DB::table('tx_billing_work')->value('status'));
        $this->assertSame(1, (int) DB::table('tx_billing_work')->value('attempts'));
        $this->assertSame([], app(NativeBillingRecovery::class)->recover());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
        $this->assertSame(1, DB::table('tx_credential_provider_operation')->count());
    }

    public function test_crash_after_committed_fulfillment_before_work_completion_replays_the_original_operation(): void
    {
        [, $order, $settlement] = $this->paid();
        $crash = true;
        DB::listen(function (QueryExecuted $query) use (&$crash): void {
            if ($crash && str_starts_with(strtolower($query->sql), 'update')
                && str_contains($query->sql, 'tx_billing_work') && in_array('completed', $query->bindings, true)) {
                $crash = false;
                throw new \RuntimeException('simulated worker termination after fulfillment commit');
            }
        });
        $first = app(NativeBillingRecovery::class)->recover();
        $this->assertFalse($crash, 'The failure must occur at the post-fulfillment work marker.');
        $this->assertSame(['status' => 'pending', 'error_code' => 'BILLING_WORK_FAILED'],
            $first[$settlement['work_id']]);
        $this->assertSame('pending', DB::table('tx_billing_work')->value('status'));
        $original = DB::table('tx_subscription_operation')->where('order_id', $order->id)->first();
        $this->assertNotNull($original);
        $second = app(NativeBillingRecovery::class)->recover();
        $this->assertSame('completed', $second[$settlement['work_id']]['status']);
        $this->assertSame(2, (int) DB::table('tx_billing_work')->value('attempts'));
        $this->assertSame($original->content_hash, DB::table('tx_subscription_operation')->value('content_hash'));
        $this->assertSame(1, DB::table('tx_subscription')->count());
        $this->assertSame(1, DB::table('tx_subscription_terms')->count());
        $this->assertSame(1, DB::table('tx_usage_period')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
        $this->assertSame(1, DB::table('tx_credential_provider_operation')->count());
    }

    public function test_failed_account_admission_retains_money_and_pending_work_until_the_account_can_fulfill(): void
    {
        [$user, , $settlement] = $this->paid();
        DB::table('tx_user')->where('id', $user->id)->update(['banned' => true]);
        $first = app(NativeBillingRecovery::class)->recover();
        $this->assertSame('pending', $first[$settlement['work_id']]['status']);
        $this->assertSame('NATIVE_ACCOUNT_BANNED', $first[$settlement['work_id']]['error_code']);
        $this->assertSame(1, (int) DB::table('tx_billing_work')->value('attempts'));
        $this->assertSame(0, DB::table('tx_subscription')->count());
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
        DB::table('tx_user')->where('id', $user->id)->update(['banned' => false]);
        $this->assertSame('completed', app(NativeBillingRecovery::class)->recover()[$settlement['work_id']]['status']);
        $this->assertNull(DB::table('tx_billing_work')->value('error_code'));
    }

    public function test_damaged_money_evidence_cannot_fulfill_and_pending_work_records_a_nonsecret_error(): void
    {
        [, , $settlement] = $this->paid();
        $hash = DB::table('tx_money_journal')->where('id', $settlement['journal_id'])->value('request_hash');
        DB::table('tx_money_journal')->where('id', $settlement['journal_id'])->update(['request_hash' => str_repeat('0', 64)]);
        $first = app(NativeBillingRecovery::class)->recover();
        $this->assertSame('pending', $first[$settlement['work_id']]['status']);
        $this->assertMatchesRegularExpression('/\A(?:BILLING|PAYMENT)_[A-Z0-9_]{1,50}\z/',
            $first[$settlement['work_id']]['error_code']);
        $this->assertSame(0, DB::table('tx_subscription')->count());
        $this->assertSame(1, (int) DB::table('tx_billing_work')->value('attempts'));
        DB::table('tx_money_journal')->where('id', $settlement['journal_id'])->update(['request_hash' => $hash]);
        $this->assertSame('completed', app(NativeBillingRecovery::class)->recover()[$settlement['work_id']]['status']);
    }

    public function test_provider_transport_uncertainty_does_not_erase_committed_fulfillment_or_its_durable_credential_retry(): void
    {
        [, , $settlement] = $this->paid();
        $this->loseProviderResponse = true;
        $this->assertSame('completed', app(NativeBillingRecovery::class)->recover()[$settlement['work_id']]['status']);
        $this->assertSame('pending', DB::table('tx_native_runtime_outbox')->value('status'));
        $this->assertSame('unknown', DB::table('tx_credential_provider_operation')->value('status'));
        $this->loseProviderResponse = false;
        $this->assertSame(0, Artisan::call('core:native-work', ['--limit' => 1]));
        $this->assertSame('completed', DB::table('tx_native_runtime_outbox')->value('status'));
        $this->assertSame('active', DB::table('tx_access_subject')->value('status'));
        $this->assertSame(1, DB::table('tx_subscription_operation')->count());
        $this->assertSame(1, DB::table('tx_credential_provider_operation')->count());
    }

    public function test_scan_is_bounded_and_invalid_limit_or_outer_transaction_cannot_execute_work(): void
    {
        [, , $first] = $this->paid('first-payment');
        [, , $second] = $this->paid('second-payment');
        foreach ([0, 1001] as $limit) {
            $this->rejects('BILLING_WORK_LIMIT_INVALID', fn () => app(NativeBillingRecovery::class)->recover($limit));
        }
        DB::transaction(function (): void {
            $this->rejects('BILLING_OUTER_TRANSACTION_UNSUPPORTED', fn () => app(NativeBillingRecovery::class)->recover());
        });
        $this->assertSame(0, DB::table('tx_subscription')->count());
        $result = app(NativeBillingRecovery::class)->recover(1);
        $this->assertSame([$first['work_id']], array_map('strval', array_keys($result)));
        $this->assertSame('pending', DB::table('tx_billing_work')->where('id', $second['work_id'])->value('status'));
        $this->assertSame(0, (int) DB::table('tx_billing_work')->where('id', $second['work_id'])->value('attempts'));
    }

    public function test_independent_mysql_workers_contending_for_one_paid_order_grant_it_once(): void
    {
        if (getenv('CORE_NATIVE_TEST_MYSQL') !== '1' || DB::getDriverName() !== 'mysql'
            || !function_exists('pcntl_fork') || !function_exists('posix_kill')
            || !function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Requires dedicated MySQL and independent POSIX workers.');
        }
        [, , $settlement] = $this->paid();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$parent, $child] = $pair;
        foreach (array_keys(DB::getConnections()) as $name) { DB::purge($name); }
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($parent);
            stream_set_timeout($child, 20);
            try {
                DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
                if (trim((string) fgets($child)) !== 'RECOVER') { throw new \RuntimeException('START_SIGNAL_MISSING'); }
                $announced = false;
                DB::connection()->beforeExecuting(function (string $sql) use ($child, &$announced): void {
                    if (!$announced && str_contains(strtolower($sql), '`tx_user`')
                        && str_contains(strtolower($sql), 'for update')) {
                        $announced = true;
                        fwrite($child, "ACCOUNT_LOCK\n");
                        fflush($child);
                    }
                });
                $result = ['results' => app(NativeBillingRecovery::class)->recover(1), 'contended' => $announced];
            } catch (\Throwable $error) {
                $result = ['error' => $error::class, 'message' => $error->getMessage()];
            }
            fwrite($child, json_encode($result, JSON_THROW_ON_ERROR)."\n");
            fflush($child);
            DB::disconnect();
            fclose($child);
            exit(0);
        }
        fclose($child);
        stream_set_timeout($parent, 20);
        $finished = false;
        $released = false;
        try {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
            DB::connection()->beforeExecuting(function (string $sql) use ($parent, &$released): void {
                if ($released || !str_contains(strtolower($sql), 'insert into `tx_subscription`')) { return; }
                $released = true;
                fwrite($parent, "RECOVER\n");
                fflush($parent);
                $this->assertSame('ACCOUNT_LOCK', trim((string) fgets($parent)));
                $read = [$parent];
                $write = [];
                $except = [];
                $this->assertSame(0, stream_select($read, $write, $except, 0, 250000),
                    'The other worker must wait for the original owning transaction.');
            });
            $recovered = app(NativeBillingRecovery::class)->recover(1);
            $this->assertSame('completed', $recovered[$settlement['work_id']]['status'],
                json_encode($recovered, JSON_THROW_ON_ERROR));
            $this->assertTrue($released);
            $result = json_decode((string) fgets($parent), true, 32, JSON_THROW_ON_ERROR);
            $finished = true;
            $this->assertArrayNotHasKey('error', $result, json_encode($result, JSON_THROW_ON_ERROR));
            $this->assertTrue($result['contended']);
            $this->assertSame('completed', $result['results'][$settlement['work_id']]['status'],
                json_encode($result, JSON_THROW_ON_ERROR));
            $this->assertSame(1, DB::table('tx_subscription')->count());
            $this->assertSame(1, DB::table('tx_subscription_operation')->count());
            $this->assertSame(1, DB::table('tx_usage_period')->count());
            $this->assertSame(1, DB::table('tx_money_journal')->count());
            $this->assertSame('completed', DB::table('tx_billing_work')->value('status'));
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

    private function paid(string $transaction = 'recoverable-payment'): array
    {
        [$user, $order] = $this->purchase();
        $attempt = app(NativePaymentAttempts::class)->prepare(
            $user->id, $order->id, $this->payment->id, 'merchant-1', $transaction);
        $settlement = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => $transaction]));
        return [$user, $order, $settlement];
    }
}
