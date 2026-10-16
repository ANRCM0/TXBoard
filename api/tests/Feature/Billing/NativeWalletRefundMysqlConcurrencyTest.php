<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\BillingError;
use App\Domains\Billing\NativeBillingRecovery;
use App\Domains\Billing\NativeBillingTransaction;
use App\Domains\Billing\NativePaymentAttempts;
use App\Domains\Billing\NativePaymentSettlement;
use App\Domains\Billing\NativeRechargeService;
use App\Domains\Billing\NativeRefundService;
use App\Domains\Billing\NativeWalletPayments;
use App\Domains\Billing\NativeWalletReservations;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use App\Models\User;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativePaymentTestCase;

/** Real independent MySQL sessions; these financial races run only in dedicated CI databases. */
final class NativeWalletRefundMysqlConcurrencyTest extends NativePaymentTestCase
{
    protected string $publicationCurrency = 'CNY';

    protected function setUp(): void
    {
        parent::setUp();
        $this->payment->update(['payment' => 'EPay', 'config' => [
            'url' => 'https://epay.example.test', 'pid' => '42', 'key' => 'mysql-test-secret', 'type' => 'alipay',
        ]]);
    }

    public function test_simultaneous_wallet_payment_replays_capture_once_and_share_one_allocation_and_work(): void
    {
        $this->requireMysqlProcesses();
        [$user, $order] = $this->purchase();
        $this->fund($user);
        $pay = fn (): array => app(NativeWalletPayments::class)->pay($user->id, $order->id, 'concurrent-wallet');
        $results = $this->race([$pay, $pay, $pay, $pay]);
        $allocations = array_map(fn (array $result): string => $result['payment']['order_payment_id'], $results);
        $this->assertCount(1, array_unique($allocations));
        $this->assertSame(1, DB::table('tx_wallet_order_reservation')->where('status', 'captured')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->where('business_type', 'wallet_reserve')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->where('business_type', 'wallet_capture')->count());
        $this->assertSame(1, DB::table('tx_order_payment')->count());
        $this->assertSame(1, DB::table('tx_billing_work')->count());
        $this->assertSame('3766', $this->walletBalance($user));
        $this->assertSame('0', $this->walletBalance($user, 'wallet_reserved'));
        $this->assertSame(1, DB::table('tx_payment_receipt')->count(), 'The recharge is the only actual channel receipt.');
    }

    public function test_competing_orders_cannot_overspend_one_wallet_even_with_different_request_keys(): void
    {
        $this->requireMysqlProcesses();
        [$user, $first] = $this->purchase();
        [, $second] = $this->purchase($user);
        $this->fund($user, '2000');
        $results = $this->race([
            fn (): array => $this->outcome(fn (): array => app(NativeWalletPayments::class)->pay($user->id, $first->id, 'first-spend')),
            fn (): array => $this->outcome(fn (): array => app(NativeWalletPayments::class)->pay($user->id, $second->id, 'second-spend')),
        ]);
        $this->assertCount(1, array_filter($results, fn (array $result): bool => isset($result['payment'])));
        $this->assertSame(['BILLING_INSUFFICIENT_FUNDS'], array_values(array_filter(array_column($results, 'error_code'))));
        $this->assertSame('766', $this->walletBalance($user));
        $this->assertSame('0', $this->walletBalance($user, 'wallet_reserved'));
        $this->assertSame(1, DB::table('tx_order_payment')->count());
        $this->assertSame(1, DB::table('tx_wallet_order_reservation')->count());
        $this->assertSame(1, DB::table('tx_order')->where('status', Order::STATUS_PROCESSING)->count());
        $this->assertSame(1, DB::table('tx_order')->where('status', Order::STATUS_PENDING)->count());
    }

    public function test_same_refund_key_concurrently_returns_one_credit_and_one_immutable_refund(): void
    {
        $this->requireMysqlProcesses();
        [$user, $order, $paid] = $this->channelPayment();
        $admin = $this->admin();
        $refund = fn (): array => app(NativeRefundService::class)->refundToWallet($user->id, 'receipt',
            (int) $paid['receipt_id'], '1234', 'one-refund', $admin->id, 'customer_request');
        $results = $this->race([$refund, $refund, $refund, $refund]);
        $this->assertCount(1, array_unique(array_column($results, 'refund_id')));
        $this->assertSame(1, DB::table('tx_native_refund')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->where('business_type', 'refund')->count());
        $this->assertSame('1234', $this->walletBalance($user));
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertSame('cancelled', DB::table('tx_billing_work')->value('status'));
    }

    public function test_distinct_concurrent_refund_keys_cannot_exceed_the_same_receipt_limit(): void
    {
        $this->requireMysqlProcesses();
        [$user, $order, $paid] = $this->channelPayment();
        $admin = $this->admin();
        $results = $this->race([
            fn (): array => $this->outcome(fn (): array => app(NativeRefundService::class)->refundToWallet(
                $user->id, 'receipt', (int) $paid['receipt_id'], '800', 'refund-left', $admin->id, 'customer_request')),
            fn (): array => $this->outcome(fn (): array => app(NativeRefundService::class)->refundToWallet(
                $user->id, 'receipt', (int) $paid['receipt_id'], '800', 'refund-right', $admin->id, 'customer_request')),
        ]);
        $this->assertCount(1, array_filter($results, fn (array $result): bool => isset($result['refund_id'])));
        $this->assertSame(['REFUND_AMOUNT_EXCEEDS_SOURCE'], array_values(array_filter(array_column($results, 'error_code'))));
        $this->assertSame('800', $this->walletBalance($user));
        $this->assertSame('800', (string) DB::table('tx_native_refund')->value('cumulative_minor'));
        $this->assertSame(1, DB::table('tx_native_refund')->count());
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
    }

    public function test_concurrent_mixed_components_make_one_full_entitlement_decision_without_losing_either_refund(): void
    {
        $this->requireMysqlProcesses();
        [$user, $order] = $this->purchase();
        $this->fund($user);
        $this->reserve($user, $order, '500');
        $attempt = app(NativePaymentAttempts::class)->prepare($user->id, $order->id,
            $this->payment->id, 'merchant-1', 'mixed-channel');
        $paid = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => 'mixed-real-provider-payment']));
        $admin = $this->admin();
        $results = $this->race([
            fn (): array => app(NativeRefundService::class)->refundToWallet($user->id, 'receipt',
                (int) $paid['receipt_id'], '734', 'mixed-channel-refund', $admin->id, 'customer_request'),
            fn (): array => app(NativeRefundService::class)->refundToWallet($user->id, 'wallet_payment',
                (int) $paid['order_payment_id'], '500', 'mixed-wallet-refund', $admin->id, 'customer_request'),
        ]);
        $effects = array_column($results, 'entitlement_effect');
        sort($effects, SORT_STRING);
        $this->assertSame(['partial_retained', 'unfulfilled_cancelled'], $effects);
        $this->assertSame('5734', $this->walletBalance($user));
        $this->assertSame(2, DB::table('tx_native_refund')->count());
        $this->assertSame('1234', (string) DB::table('tx_native_refund')->sum('amount_minor'));
        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertSame('cancelled', DB::table('tx_billing_work')->value('status'));
    }

    public function test_refund_and_fulfillment_race_cannot_leave_paid_rights_active_after_full_refund(): void
    {
        $this->requireMysqlProcesses();
        [$user, $order, $paid] = $this->channelPayment();
        $admin = $this->admin();
        $at = time() - 5;
        $results = $this->race([
            fn (): array => app(NativeRefundService::class)->refundToWallet($user->id, 'receipt',
                (int) $paid['receipt_id'], '1234', 'fulfillment-race-refund', $admin->id, 'customer_request'),
            fn (): array => $this->outcome(fn (): array => app(SubscriptionFulfillmentService::class)->fulfill($order->id, $at)),
        ]);
        $this->assertSame('wallet_credited', $results[0]['status']);
        $this->assertSame('1234', $this->walletBalance($user));
        $this->assertSame(0, DB::table('tx_subscription')->whereIn('status', ['active', 'suspended'])->count());
        if (isset($results[1]['error_code'])) {
            $this->assertContains($results[1]['error_code'], ['NATIVE_ORDER_NOT_PROCESSING', 'REFUND_ORDER_FULLY_REFUNDED']);
            $this->assertSame('unfulfilled_cancelled', $results[0]['entitlement_effect']);
            $this->assertSame(0, DB::table('tx_subscription')->count());
        } else {
            $this->assertSame('subscription_revoked', $results[0]['entitlement_effect']);
            $this->assertSame('revoked', DB::table('tx_subscription')->value('status'));
            $this->assertSame('closed', DB::table('tx_usage_period')->value('status'));
            $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        }
        $this->assertSame('cancelled', DB::table('tx_billing_work')->value('status'));
        $this->assertSame([], app(NativeBillingRecovery::class)->recover());
    }

    private function outcome(\Closure $operation): array
    {
        try {
            return $operation();
        } catch (\DomainException $error) {
            return ['error_code' => $error instanceof BillingError ? $error->errorCode : $error->getMessage()];
        }
    }

    private function fund(User $user, string $amount = '5000'): void
    {
        $recharge = app(NativeRechargeService::class)->create($user->id, $amount, $this->payment->id, 'fund-wallet');
        $attempt = app(NativePaymentAttempts::class)->prepareRecharge($user->id, (int) $recharge['recharge_id'],
            $this->payment->id, 'merchant-1', 'fund-wallet-attempt');
        app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => 'real-wallet-funds']));
    }

    private function channelPayment(): array
    {
        [$user, $order] = $this->purchase();
        $attempt = app(NativePaymentAttempts::class)->prepare($user->id, $order->id,
            $this->payment->id, 'merchant-1', 'channel-purchase');
        $paid = app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'], $this->fact($attempt));
        return [$user, $order, $paid];
    }

    private function reserve(User $user, Order $order, string $amount): void
    {
        app(NativeBillingTransaction::class)->run($user->id, function (array $installation) use ($user, $order, $amount): void {
            app(NativeSubscriptionStore::class)->lockAccount($user->id, time(), $installation['installation_id']);
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $terms = OrderPurchaseTerms::query()->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            app(NativeWalletReservations::class)->reserveLocked($installation, $user->id, $locked, $terms, $amount, 'mixed-wallet');
        });
    }

    private function admin(): User
    {
        [, $admin] = app(NativeIdentityService::class)->create('mysql-admin@example.test', 'runtime-password');
        DB::table('tx_user')->where('id', $admin->id)->update(['is_admin' => true]);
        return $admin;
    }

    private function walletBalance(User $user, string $purpose = 'wallet_available'): string
    {
        return (string) (DB::table('tx_money_account')->where('owner_id', $user->id)->where('purpose', $purpose)->value('balance_minor') ?? '0');
    }

    private function race(array $operations): array
    {
        $directory = sys_get_temp_dir().'/txboard-native-wallet-refund-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];
        foreach (array_keys(DB::getConnections()) as $name) { DB::purge($name); }
        try {
            foreach ($operations as $index => $operation) {
                $pid = pcntl_fork();
                if ($pid < 0) { throw new \RuntimeException('Wallet/refund concurrency fork failed.'); }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                        DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
                        $deadline = microtime(true) + 20;
                        while (!is_file($directory.'/start') && microtime(true) < $deadline) { usleep(1000); }
                        if (!is_file($directory.'/start')) { throw new \RuntimeException('Wallet/refund barrier timeout.'); }
                        file_put_contents($directory.'/'.$index, json_encode($operation(), JSON_THROW_ON_ERROR));
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

    private function requireMysqlProcesses(): void
    {
        if (getenv('CORE_NATIVE_TEST_MYSQL') !== '1' || DB::getDriverName() !== 'mysql'
            || !function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Requires dedicated native MySQL and independent POSIX sessions.');
        }
    }
}
