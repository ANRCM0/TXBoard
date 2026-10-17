<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\BillingError;
use App\Domains\Billing\NativeOrderLifecycle;
use App\Domains\Billing\NativePaymentAttempts;
use App\Domains\Billing\NativePaymentSettlement;
use App\Domains\Billing\NativeRefundService;
use App\Domains\Billing\OrderIdempotency;
use App\Domains\Subscription\Services\PlanPublicationService;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativePaymentTestCase;

/** Source claims and wallet refunds are serialized through real independent MySQL sessions. */
final class NativeUpgradeCreditMysqlConcurrencyTest extends NativePaymentTestCase
{
    protected string $publicationCurrency = 'CNY';

    public function test_quote_and_full_wallet_refund_use_current_source_budget_in_both_commit_orders(): void
    {
        $this->requireMysqlProcesses();
        foreach ([true, false] as $quoteFirst) {
            [$user, $source, $paid] = $this->paidSource();
            $offerId = $this->targetOffer($source);
            $admin = $this->admin();
            $quote = fn (): array => ['order_id' => (string) app(PublishedOrderService::class)
                ->create($user->id, $offerId, 'quote-refund-race')->id];
            $refund = fn (): array => $this->outcome(fn (): array => app(NativeRefundService::class)
                ->refundToWallet($user->id, 'receipt', (int) $paid['receipt_id'],
                    '1234', 'refund-quote-race', $admin->id, 'customer_request'));
            $results = $this->race($quoteFirst ? [$quote, $refund] : [$refund, $quote]);
            $quoted = $results[$quoteFirst ? 0 : 1];
            $refunded = $results[$quoteFirst ? 1 : 0];
            $order = Order::query()->findOrFail($quoted['order_id']);
            $row = DB::table('tx_order_upgrade_credit')->where('order_id', $order->id)->first();
            if ($quoteFirst) {
                $this->assertNotNull($row);
                $this->assertGreaterThan(0, (int) $row->credit_minor);
                $this->assertSame('REFUND_AMOUNT_RESERVED_FOR_UPGRADE', $refunded['error_code']);
                $this->assertSame(0, DB::table('tx_native_refund')->where('user_id', $user->id)->count());
                $this->assertSame('0', $this->walletBalance($user));
                $this->assertSame(1, DB::table('tx_upgrade_credit_source')
                    ->where('order_payment_id', $paid['order_payment_id'])->count());
            } else {
                $this->assertSame('wallet_credited', $refunded['status']);
                $this->assertSame('none', $refunded['entitlement_effect']);
                $this->assertNotNull($row, 'Even zero-credit upgrades retain a source fingerprint.');
                $this->assertSame('0', (string) $row->credit_minor, 'Fully refunded cash supplies no credit.');
                $this->assertSame('3000', (string) $order->total_amount);
                $this->assertSame('1234', $this->walletBalance($user));
                $this->assertSame(0, DB::table('tx_upgrade_credit_source')
                    ->where('order_payment_id', $paid['order_payment_id'])->count());
            }
            $this->assertSame(Order::STATUS_PENDING, (int) $order->status);
            $this->assertSame('active', DB::table('tx_subscription')->where('user_id', $user->id)->value('status'));
            $this->assertSame(Order::STATUS_COMPLETED, (int) $source->fresh()->status);
        }
    }

    public function test_same_quote_key_replays_one_permanent_order_quote_and_source_claim_after_an_older_snapshot(): void
    {
        $this->requireMysqlProcesses();
        [$user, $source, $paid] = $this->paidSource();
        $offerId = $this->targetOffer($source);
        $quote = fn (): array => ['order_id' => (string) app(PublishedOrderService::class)
            ->create($user->id, $offerId, 'one-permanent-upgrade')->id];
        $results = $this->race([$quote, $quote, $quote, $quote]);
        $this->assertCount(1, array_unique(array_column($results, 'order_id')));
        $target = Order::query()->findOrFail($results[0]['order_id']);
        $this->assertSame(Order::TYPE_UPGRADE, (int) $target->type);
        $this->assertSame(1, DB::table('tx_order_upgrade_credit')->where('order_id', $target->id)->count());
        $this->assertSame(1, DB::table('tx_upgrade_credit_source')
            ->where('order_payment_id', $paid['order_payment_id'])->count());
        $this->assertSame(1, DB::table('idempotency_keys')->where('user_id', $user->id)
            ->where('operation', OrderIdempotency::OPERATION_NATIVE_CREATE)->count());
        $this->assertSame((string) PHP_INT_MAX, (string) DB::table('idempotency_keys')->where('user_id', $user->id)
            ->where('operation', OrderIdempotency::OPERATION_NATIVE_CREATE)->value('expires_at'));
        $evidence = DB::table('tx_order_upgrade_credit')->where('order_id', $target->id)->first();
        $this->assertTrue((new NativeOrderLifecycle($target))->cancel());
        $replay = app(PublishedOrderService::class)->create($user->id, $offerId, 'one-permanent-upgrade');
        $this->assertSame((string) $target->id, (string) $replay->id);
        $this->assertSame(Order::STATUS_CANCELLED, (int) $replay->status);
        $this->assertEquals($evidence, DB::table('tx_order_upgrade_credit')->where('order_id', $target->id)->first());
        $this->assertSame(1, DB::table('tx_payment_receipt')->count(), 'Quoting never fabricates a channel payment.');
        $this->assertSame(1, DB::table('tx_money_journal')->count());
    }

    public function test_cancel_and_duplicate_channel_settlement_commit_once_with_claim_release_or_consumption(): void
    {
        $this->requireMysqlProcesses();
        foreach ([true, false] as $cancelFirst) {
            [$user, $source, $paid] = $this->paidSource();
            $offerId = $this->targetOffer($source);
            $target = app(PublishedOrderService::class)->create($user->id, $offerId, 'cancel-settle-upgrade');
            $attempt = app(NativePaymentAttempts::class)->prepare($user->id, $target->id,
                $this->payment->id, 'merchant-1', 'upgrade-channel-attempt');
            $fact = $this->fact($attempt, ['transaction_ref' => 'upgrade-channel:'.$target->id]);
            $settle = fn (): array => app(NativePaymentSettlement::class)
                ->settle($user->id, (int) $attempt['attempt_id'], $fact);
            $cancel = fn (): array => ['cancelled' => (new NativeOrderLifecycle($target))->cancel()];
            $results = $this->race($cancelFirst ? [$cancel, $settle, $settle] : [$settle, $cancel, $settle]);
            $firstSettlement = $results[$cancelFirst ? 1 : 0];
            $this->assertSame($firstSettlement, $results[2]);
            $this->assertSame($cancelFirst, $results[$cancelFirst ? 0 : 1]['cancelled']);
            $this->assertSame(1, DB::table('tx_payment_receipt')->where('attempt_id', $attempt['attempt_id'])->count());
            $this->assertSame(1, DB::table('tx_payment_settlement')->where('attempt_id', $attempt['attempt_id'])->count());
            $this->assertSame(1, DB::table('tx_money_journal')->where('business_type', 'provider_capture')
                ->where('business_ref', $attempt['attempt_id'])->count());
            $admin = $this->admin();
            $refund = fn (): array => app(NativeRefundService::class)->refundToWallet($user->id, 'receipt',
                (int) $paid['receipt_id'], '1234', 'original-after-cancel-race', $admin->id, 'customer_request');
            if ($cancelFirst) {
                $this->assertSame('refund_liability', $firstSettlement['decision']);
                $this->assertSame(Order::STATUS_CANCELLED, (int) $target->fresh()->status);
                $this->assertSame(0, DB::table('tx_order_payment')->where('order_id', $target->id)->count());
                $this->assertSame((string) $source->plan_id, (string) DB::table('tx_subscription')
                    ->where('user_id', $user->id)->value('plan_id'));
                $this->assertSame('wallet_credited', $refund()['status'], 'Cancelled quotes release source cash.');
                $this->assertSame('1234', $this->walletBalance($user));
            } else {
                $this->assertSame('order_payment', $firstSettlement['decision']);
                $this->assertSame(Order::STATUS_COMPLETED, (int) $target->fresh()->status);
                $this->assertSame(1, DB::table('tx_order_payment')->where('order_id', $target->id)->count());
                $this->assertSame((string) $target->plan_id, (string) DB::table('tx_subscription')
                    ->where('user_id', $user->id)->value('plan_id'));
                $this->rejects('REFUND_AMOUNT_RESERVED_FOR_UPGRADE', $refund);
                $this->assertSame('0', $this->walletBalance($user));
            }
            $this->assertSame('active', DB::table('tx_subscription')->where('user_id', $user->id)->value('status'));
        }
    }

    private function paidSource(): array
    {
        [$user, $order] = $this->purchase();
        $attempt = app(NativePaymentAttempts::class)->prepare($user->id, $order->id,
            $this->payment->id, 'merchant-1', 'source-payment');
        $paid = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => 'source-channel:'.$order->id]));
        app(SubscriptionFulfillmentService::class)->fulfill($order->id, time() - 60);
        return [$user, $order, $paid];
    }

    private function targetOffer(Order $source): int
    {
        $base = Plan::query()->findOrFail($source->plan_id);
        $target = Plan::withoutEvents(function () use ($base): Plan {
            $target = $base->replicate();
            $target->name = 'Upgrade target';
            $target->prices = ['monthly' => '30.00'];
            $target->saveOrFail();
            return $target;
        });
        $offers = app(PlanPublicationService::class)->publish($target->id, 'target-publication', 'CNY', 'UTC')['offers'];
        return (int) collect($offers)->firstWhere('offer_code', 'monthly')['offer_id'];
    }

    private function admin(): User
    {
        [, $user] = app(NativeIdentityService::class)->create('upgrade-admin-'.bin2hex(random_bytes(8)).'@example.test', 'runtime-password');
        DB::table('tx_user')->where('id', $user->id)->update(['is_admin' => true]);
        return $user;
    }

    private function walletBalance(User $user): string
    {
        return (string) (DB::table('tx_money_account')->where('owner_id', $user->id)
            ->where('purpose', 'wallet_available')->value('balance_minor') ?? '0');
    }

    private function outcome(\Closure $operation): array
    {
        try {
            return $operation();
        } catch (BillingError $error) {
            return ['error_code' => $error->errorCode];
        }
    }

    /** Followers pin an admission-time RR snapshot, then start business locks only after the leader commits. */
    private function race(array $operations): array
    {
        $directory = sys_get_temp_dir().'/txboard-upgrade-credit-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];
        foreach (array_keys(DB::getConnections()) as $name) { DB::purge($name); }
        try {
            foreach ($operations as $index => $operation) {
                $pid = pcntl_fork();
                if ($pid < 0) { throw new \RuntimeException('Upgrade concurrency fork failed.'); }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                        DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
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
                                DB::table('tx_money_journal')->limit(1)->get(['id']);
                                touch($directory.'/snapshot-'.$index);
                                $this->awaitFile($directory.'/committed');
                            }
                        });
                        $this->awaitFile($directory.'/start');
                        file_put_contents($directory.'/'.$index, json_encode($operation(), JSON_THROW_ON_ERROR));
                        if ($index === 0) { touch($directory.'/committed'); }
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
        $deadline = microtime(true) + 30;
        while (!is_file($path) && microtime(true) < $deadline) { usleep(1000); }
        if (!is_file($path)) { throw new \RuntimeException('Upgrade snapshot barrier timeout.'); }
    }

    private function requireMysqlProcesses(): void
    {
        if (getenv('CORE_NATIVE_TEST_MYSQL') !== '1' || DB::getDriverName() !== 'mysql'
            || !function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Requires dedicated native MySQL and independent POSIX sessions.');
        }
    }
}
