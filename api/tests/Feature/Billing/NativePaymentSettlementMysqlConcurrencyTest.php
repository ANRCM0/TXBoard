<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\NativePaymentAttempts;
use App\Domains\Billing\BillingError;
use App\Domains\Billing\NativePaymentCheckout;
use App\Domains\Billing\OrderCheckout;
use App\Domains\Billing\NativePaymentSettlement;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Models\Order;
use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\NativePaymentTestCase;

/** Independent MySQL sessions contend on the same real account and order decision. */
final class NativePaymentSettlementMysqlConcurrencyTest extends NativePaymentTestCase
{
    protected string $publicationCurrency = 'CNY';

    public function test_package_delete_window_cannot_reinstall_enable_and_pin_a_fresh_dispatch(): void
    {
        $this->requireMysqlProcesses();
        config(['app.url' => 'https://core.example.test']);
        $this->payment->update(['payment' => 'EPay', 'config' => [
            'url' => 'https://epay.example.test', 'pid' => '42', 'key' => 'delete-race-secret', 'type' => 'alipay',
        ]]);
        Plugin::query()->where('code', 'epay')->update(['is_enabled' => false]);
        [$user, $order] = $this->purchase();
        $code = 'native_delete_race';
        $path = app(PluginManager::class)->getUserPluginPath($code);
        $window = sys_get_temp_dir().'/native-delete-window-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists($path);
        $filesystem->makeDirectory($window, 0700);
        $filesystem->put($path.'/config.json', json_encode(['package' => ['schema' => 1],
            'code' => $code, 'name' => 'Delete race', 'version' => '1.0.0', 'type' => 'payment',
            'payment' => ['contract' => 'native-payment-provider/1', 'provider' => 'EPay',
                'snapshot_schema' => 'epay-md5-dispatch/1'],
            'description' => 'Delete versus fresh native install', 'author' => 'Tests'], JSON_THROW_ON_ERROR));
        $filesystem->put($path.'/Plugin.php', '<?php namespace Plugin\\NativeDeleteRace; '
            .'class Plugin extends \\App\\Services\\Plugin\\AbstractPlugin implements \\App\\Contracts\\NativePaymentPluginInterface {'
            .'public function nativePaymentPackageVersion(): string { return "1.0.0"; }'
            .'public function form(): array { return []; }'
            .'public function nativePaymentProvider(): \\App\\Contracts\\NativePaymentProviderInterface {'
            .'require_once base_path("plugins-core/Epay/NativeAdapter.php"); return new \\Plugin\\Epay\\NativeAdapter; }}');
        Plugin::query()->create(['code' => $code, 'name' => 'Delete race', 'type' => Plugin::TYPE_PAYMENT,
            'version' => '1.0.0', 'is_enabled' => false, 'config' => '{}', 'installed_at' => now()]);
        try {
            $results = $this->race([
                function () use ($code, $path, $window, $filesystem): array {
                    File::partialMock()->shouldReceive('deleteDirectory')->with($path)->once()
                        ->andReturnUsing(function (string $directory) use ($window, $filesystem): bool {
                            $this->assertSame(0, DB::transactionLevel());
                            touch($window.'/deleting');
                            $deadline = microtime(true) + 15;
                            while (!is_file($window.'/install-finished') && microtime(true) < $deadline) { usleep(1000); }
                            if (!is_file($window.'/install-finished')) { throw new \RuntimeException('Delete race timeout'); }
                            return $filesystem->deleteDirectory($directory);
                        });
                    return ['deleted' => app(PluginManager::class)->delete($code)];
                },
                function () use ($code, $window, $user, $order): array {
                    try {
                        $deadline = microtime(true) + 15;
                        while (!is_file($window.'/deleting') && microtime(true) < $deadline) { usleep(1000); }
                        if (!is_file($window.'/deleting')) { throw new \RuntimeException('Install race timeout'); }
                        $manager = app(PluginManager::class);
                        $manager->install($code);
                        $manager->enable($code);
                        app(NativePaymentCheckout::class)->start(
                            $user->id, $order->id, $this->payment->id, 'delete-new-dispatch');
                        return ['created' => true];
                    } catch (BillingError $error) {
                        return ['created' => false, 'code' => $error->errorCode];
                    } finally {
                        touch($window.'/install-finished');
                    }
                },
            ]);
            $this->assertTrue($results[0]['deleted']);
            $this->assertFalse($results[1]['created']);
            $this->assertSame('PAYMENT_PLUGIN_MUTATION_IN_PROGRESS', $results[1]['code']);
            $this->assertFalse($filesystem->exists($path));
            $this->assertFalse(Plugin::query()->where('code', $code)->exists());
            $this->assertNull(DB::table('tx_plugin_package_fence')->where('plugin_code', $code)->value('mutation_token'));
            $this->assertTrue(DB::table('tx_plugin_package_fence')->where('plugin_code', $code)->exists());
            $this->assertSame(0, DB::table('tx_payment_attempt')->count());
            $this->assertSame(0, DB::table('tx_payment_dispatch')->count());
        } finally {
            $filesystem->deleteDirectory($path);
            $filesystem->deleteDirectory($window);
        }
    }

    public function test_first_dispatch_and_destructive_plugin_lifecycle_cannot_both_commit(): void
    {
        $this->requireMysqlProcesses();
        config(['app.url' => 'https://core.example.test']);
        $this->payment->update(['payment' => 'EPay', 'config' => [
            'url' => 'https://epay.example.test', 'pid' => '42', 'key' => 'race-secret', 'type' => 'alipay',
        ]]);
        [$user, $order] = $this->purchase();
        $results = $this->race([
            function () use ($user, $order): array {
                try {
                    app(NativePaymentCheckout::class)->start($user->id, $order->id, $this->payment->id, 'first-dispatch-race');
                    return ['created' => true];
                } catch (BillingError $error) { return ['created' => false, 'code' => $error->errorCode]; }
            },
            function (): array {
                try { return ['uninstalled' => app(PluginManager::class)->uninstall('epay')]; }
                catch (BillingError $error) { return ['uninstalled' => false, 'code' => $error->errorCode]; }
            },
        ]);
        $this->assertFalse($results[0]['created'] && $results[1]['uninstalled']);
        if ($results[1]['uninstalled']) {
            $this->assertSame(0, DB::table('tx_payment_dispatch')->count());
            $this->assertSame(0, DB::table('tx_payment_attempt')->count());
            $this->assertFalse(Plugin::query()->where('code', 'epay')->exists());
        } else {
            $this->assertContains($results[1]['code'], ['PAYMENT_PLUGIN_DRAIN_REQUIRED', 'PAYMENT_PLUGIN_MUTATION_IN_PROGRESS']);
            $this->assertTrue(Plugin::query()->where('code', 'epay')->exists());
        }
        $this->assertSame(0, DB::table('tx_payment_receipt')->count());
    }

    public function test_simultaneous_free_checkout_retries_share_one_durable_key_without_a_financial_receipt(): void
    {
        $this->requireMysqlProcesses();
        [$user, $order] = $this->purchase(period: 'onetime');
        $operation = fn (): array => app(OrderCheckout::class)->start(
            $user->id, $order->trade_no, requestKey: 'same-free-checkout');
        $results = $this->race([$operation, $operation, $operation, $operation]);
        $this->assertSame(array_fill(0, 4, ['type' => -1, 'data' => true]), $results);
        $this->assertSame(1, DB::table('tx_native_free_checkout')->count());
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertSame(0, DB::table('tx_payment_receipt')->count());
        $this->assertSame(0, DB::table('tx_money_journal')->count());
    }

    public function test_simultaneous_duplicate_successes_commit_one_receipt_journal_allocation_and_work(): void
    {
        $this->requireMysqlProcesses();
        [$user, $order] = $this->purchase();
        $attempt = app(NativePaymentAttempts::class)->prepare($user->id, $order->id, $this->payment->id, 'merchant-1', 'same-attempt');
        $operation = fn (): array => app(NativePaymentSettlement::class)->settle(
            $user->id, (int) $attempt['attempt_id'], $this->fact($attempt, ['occurred_at' => null]));
        $results = $this->race([$operation, $operation, $operation, $operation]);
        $this->assertSame(array_fill(0, 4, $results[0]), $results);
        $this->assertSame('order_payment', $results[0]['decision']);
        $this->assertCount(1, array_unique(array_column($results, 'settlement_id')));
        $this->assertCount(1, array_unique(array_column($results, 'occurred_at')));
        foreach (['tx_payment_receipt', 'tx_money_journal', 'tx_payment_settlement', 'tx_order_payment', 'tx_billing_work'] as $table) {
            $this->assertSame(1, DB::table($table)->count(), $table);
        }
        $this->assertSame($results[0]['occurred_at'], (string) DB::table('tx_payment_receipt')->value('occurred_at'));
        $this->assertSame($results[0]['occurred_at'], (string) DB::table('tx_money_journal')->value('occurred_at'));
        $this->assertSame($results[0]['occurred_at'], (string) $order->fresh()->paid_at);
        $this->assertSame('1234', $this->balance('provider_clearing'));
        $this->assertSame('1234', $this->balance('sales_revenue'));
    }

    public function test_two_successful_attempts_choose_one_payment_and_preserve_the_other_as_refund_liability(): void
    {
        $this->requireMysqlProcesses();
        [$user, $order] = $this->purchase();
        $attempts = app(NativePaymentAttempts::class);
        $first = $attempts->prepare($user->id, $order->id, $this->payment->id, 'merchant-1', 'first-attempt');
        $second = $attempts->prepare($user->id, $order->id, $this->payment->id, 'merchant-1', 'second-attempt');
        $results = $this->race([
            fn (): array => app(NativePaymentSettlement::class)->settle(
                $user->id, (int) $first['attempt_id'], $this->fact($first, ['transaction_ref' => 'first-provider-transaction'])),
            fn (): array => app(NativePaymentSettlement::class)->settle(
                $user->id, (int) $second['attempt_id'], $this->fact($second, ['transaction_ref' => 'second-provider-transaction'])),
        ]);
        $decisions = array_column($results, 'decision');
        sort($decisions, SORT_STRING);
        $this->assertSame(['order_payment', 'refund_liability'], $decisions);
        $this->assertSame(2, DB::table('tx_payment_receipt')->count());
        $this->assertSame(2, DB::table('tx_money_journal')->count());
        $this->assertSame(2, DB::table('tx_payment_settlement')->count());
        $this->assertSame(1, DB::table('tx_order_payment')->count());
        $this->assertSame(1, DB::table('tx_billing_work')->count());
        $this->assertSame('2468', $this->balance('provider_clearing'));
        $this->assertSame('1234', $this->balance('sales_revenue'));
        $this->assertSame('1234', $this->balance('refund_liability'));
        $winner = DB::table('tx_order_payment as p')->join('tx_payment_receipt as r', 'r.id', '=', 'p.receipt_id')
            ->where('p.order_id', $order->id)->first(['r.transaction_ref']);
        $this->assertSame($winner->transaction_ref, $order->fresh()->callback_no);
    }

    public function test_cancel_and_success_race_commits_one_consistent_financial_decision(): void
    {
        $this->requireMysqlProcesses();
        [$user, $order] = $this->purchase();
        $attempt = app(NativePaymentAttempts::class)->prepare($user->id, $order->id, $this->payment->id, 'merchant-1', 'cancel-race');
        $results = $this->race([
            fn (): array => app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'], $this->fact($attempt)),
            fn (): array => app(PublishedOrderService::class)->transitionOrder($order->id, static function (Order $locked): array {
                if ((int) $locked->status !== Order::STATUS_PENDING) { return ['cancelled' => false]; }
                $locked->status = Order::STATUS_CANCELLED;
                $locked->saveOrFail();
                return ['cancelled' => true];
            }),
        ]);
        $this->assertSame(1, DB::table('tx_payment_receipt')->count());
        $this->assertSame('1234', $this->balance('provider_clearing'));
        if ($results[1]['cancelled']) {
            $this->assertSame('refund_liability', $results[0]['decision']);
            $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
            $this->assertSame('1234', $this->balance('refund_liability'));
            $this->assertSame(0, DB::table('tx_order_payment')->count());
            $this->assertSame(0, DB::table('tx_billing_work')->count());
        } else {
            $this->assertSame('order_payment', $results[0]['decision']);
            $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
            $this->assertSame('1234', $this->balance('sales_revenue'));
            $this->assertSame(1, DB::table('tx_order_payment')->count());
            $this->assertSame(1, DB::table('tx_billing_work')->count());
        }
    }

    private function balance(string $purpose): string
    {
        return (string) DB::table('tx_money_account')->where('owner_id', 0)->where('purpose', $purpose)->value('balance_minor');
    }

    private function race(array $operations): array
    {
        $directory = sys_get_temp_dir().'/txboard-native-settlement-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];
        foreach (array_keys(DB::getConnections()) as $name) { DB::purge($name); }
        try {
            foreach ($operations as $index => $operation) {
                $pid = pcntl_fork();
                if ($pid < 0) { throw new \RuntimeException('Settlement concurrency fork failed.'); }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                        DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
                        $deadline = microtime(true) + 20;
                        while (!is_file($directory.'/start') && microtime(true) < $deadline) { usleep(1000); }
                        if (!is_file($directory.'/start')) { throw new \RuntimeException('Settlement concurrency barrier timeout.'); }
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
