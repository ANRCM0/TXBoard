<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\Internal\WinningPaymentProofs;
use App\Domains\Billing\NativeBillingRecovery;
use App\Domains\Billing\NativeBillingTransaction;
use App\Domains\Billing\NativeFreeCheckout;
use App\Domains\Billing\NativeOrderLifecycle;
use App\Domains\Billing\NativePaymentAttempts;
use App\Domains\Billing\NativePaymentSettlement;
use App\Domains\Billing\NativeRechargeService;
use App\Domains\Billing\NativeWalletPayments;
use App\Domains\Billing\NativeWalletReservations;
use App\Domains\Billing\OrderCheckout;
use App\Domains\Subscription\Contracts\OrderFulfillment;
use App\Domains\Subscription\Internal\LockedOrderFulfillment;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\Support\NativePaymentTestCase;

final class OrderFulfillmentBoundaryTest extends NativePaymentTestCase
{
    protected string $publicationCurrency = 'CNY';

    protected function setUp(): void
    {
        parent::setUp();
        $this->payment->update(['payment' => 'EPay', 'config' => [
            'url' => 'https://epay.example.test', 'pid' => '42', 'key' => 'test-secret', 'type' => 'alipay',
        ]]);
    }

    public function test_ports_share_the_authoritative_services_and_are_recreated_for_the_next_scope(): void
    {
        $fulfillment = app(OrderFulfillment::class);
        $proofs = app(WinningPaymentProofs::class);
        $this->assertInstanceOf(SubscriptionFulfillmentService::class, $fulfillment);
        $this->assertSame($fulfillment, app(SubscriptionFulfillmentService::class));
        $this->assertSame($fulfillment, app(LockedOrderFulfillment::class));
        $this->assertSame($fulfillment, app(OrderFulfillment::class));
        $this->assertInstanceOf(NativePaymentSettlement::class, $proofs);
        $this->assertSame($proofs, app(NativePaymentSettlement::class));
        $this->assertSame($proofs, app(WinningPaymentProofs::class));

        $this->app->forgetScopedInstances();
        $this->assertNotSame($fulfillment, app(OrderFulfillment::class));
        $this->assertSame(app(OrderFulfillment::class), app(LockedOrderFulfillment::class));
        $this->assertNotSame($proofs, app(WinningPaymentProofs::class));
        $this->assertSame(app(WinningPaymentProofs::class), app(NativePaymentSettlement::class));
    }

    public function test_public_fulfillment_rejects_an_outer_transaction_without_changing_the_order(): void
    {
        [, $order] = $this->purchase();
        try {
            DB::transaction(fn () => app(OrderFulfillment::class)->fulfill($order->id));
            $this->fail('A fresh fulfillment must not inherit an outer transaction.');
        } catch (DomainException $error) {
            $this->assertSame('NATIVE_OUTER_TRANSACTION_UNSUPPORTED', $error->getMessage());
        }
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertSame(0, DB::table('tx_subscription_operation')->count());
    }

    public function test_sql_participants_reject_missing_transaction_context(): void
    {
        [$user, $order] = $this->purchase();
        $purchase = OrderPurchaseTerms::query()->where('order_id', $order->id)->firstOrFail();
        try {
            app(LockedOrderFulfillment::class)->applyLocked(
                [], $user, (object) [], collect(), [], $order, $purchase, time(),
            );
            $this->fail('The SQL participant accepted a caller without a transaction.');
        } catch (DomainException $error) {
            $this->assertSame('NATIVE_TRANSACTION_CONTEXT_REQUIRED', $error->getMessage());
        }
        $this->rejects('PAYMENT_SETTLEMENT_BINDING_INVALID', fn () => app(WinningPaymentProofs::class)
            ->verifyWinningPaymentLocked([], $user->id, $order, $purchase));
        $this->assertSame(0, DB::table('tx_subscription_operation')->count());
    }

    public function test_paid_public_port_commits_real_rights_then_synchronizes_and_replays_once(): void
    {
        [, $order] = $this->paid();
        $at = time() - 5;
        $first = app(OrderFulfillment::class)->fulfill($order->id, $at);
        $this->assertSame($at, $first['applied_at']);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame('active', DB::table('tx_access_subject')->value('status'));
        $this->assertSame('completed', DB::table('tx_native_runtime_outbox')->value('status'));
        // The fixture's actual Provider wire asserts transactionLevel() === 0 for every call.
        $this->assertSame(1, DB::table('tx_credential_provider_operation')->count());
        $replayed = app(OrderFulfillment::class)->fulfill($order->id, $at + 1);
        // Persisted JSON object key order is not part of the result; scalar types and values are.
        ksort($first);
        ksort($replayed);
        $this->assertSame($first, $replayed);
        $this->assertSame(1, DB::table('tx_subscription_operation')->where('order_id', $order->id)->count());
        $this->assertSame(1, DB::table('tx_subscription_terms')->where('source_order_id', $order->id)->count());
        $this->assertSame(1, DB::table('tx_credential_provider_operation')->count());
    }

    public function test_order_lifecycle_and_paid_and_free_recovery_use_the_replaceable_fresh_port(): void
    {
        [, $order, $settled] = $this->paid();
        [$freeUser, $freeOrder] = $this->purchase(period: 'onetime');
        app(OrderCheckout::class)->start($freeUser->id, $freeOrder->trade_no, requestKey: 'port-free');
        $real = app(OrderFulfillment::class);
        $calls = [];
        $port = Mockery::mock(OrderFulfillment::class);
        $port->shouldReceive('fulfill')->times(3)->andReturnUsing(function (int $orderId, ?int $now = null) use ($real, &$calls): array {
            $this->assertSame(0, DB::transactionLevel());
            $calls[] = $orderId;
            return $real->fulfill($orderId, $now);
        });
        $this->app->instance(OrderFulfillment::class, $port);
        $this->app->bind(SubscriptionFulfillmentService::class, static function (): never {
            throw new RuntimeException('CONCRETE_FULFILLMENT_MUST_NOT_BE_USED');
        });

        (new NativeOrderLifecycle($order))->open();
        $paidWork = app(NativeBillingRecovery::class)->recover();
        $this->assertSame('completed', $paidWork[$settled['work_id']]['status']);
        // Billing recovery also drains free work through the same public port.
        $this->assertSame([$order->id, $order->id, $freeOrder->id], $calls);
        $this->assertSame([], app(NativeFreeCheckout::class)->recover(10));
        $this->assertSame(Order::STATUS_COMPLETED, (int) $freeOrder->fresh()->status);
        $this->assertSame(2, DB::table('tx_subscription_operation')->count());
    }

    public function test_fulfillment_and_recovery_consume_the_proof_port_without_resolving_its_concrete(): void
    {
        [, $order, $settled] = $this->paid();
        $real = app(WinningPaymentProofs::class);
        $calls = 0;
        $proofs = Mockery::mock(WinningPaymentProofs::class);
        $proofs->shouldReceive('verifyWinningPaymentLocked')->times(3)->andReturnUsing(
            function (array $installation, int $userId, Order $locked, OrderPurchaseTerms $purchase) use ($real, &$calls): array {
                $this->assertGreaterThan(0, DB::transactionLevel());
                $calls++;
                return $real->verifyWinningPaymentLocked($installation, $userId, $locked, $purchase);
            },
        );
        $this->app->instance(WinningPaymentProofs::class, $proofs);
        $this->app->bind(NativePaymentSettlement::class, static function (): never {
            throw new RuntimeException('CONCRETE_SETTLEMENT_MUST_NOT_BE_USED');
        });
        $result = app(NativeBillingRecovery::class)->recover();
        $this->assertSame('completed', $result[$settled['work_id']]['status']);
        $this->assertSame(3, $calls);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(1, DB::table('tx_subscription_operation')->count());
    }

    public function test_public_port_refuses_damaged_channel_wallet_and_mixed_payment_evidence(): void
    {
        foreach (['channel', 'wallet', 'mixed'] as $mode) {
            [$user, $order] = $this->purchase();
            if ($mode !== 'channel') { $this->fund($user); }
            if ($mode === 'wallet') {
                app(NativeWalletPayments::class)->pay($user->id, $order->id, 'port-wallet');
            } else {
                if ($mode === 'mixed') { $this->reserve($user, $order); }
                $this->pay($user, $order);
            }
            $allocation = DB::table('tx_order_payment')->where('order_id', $order->id)->first();
            $this->assertNotNull($allocation);
            DB::table('tx_order_payment')->where('id', $allocation->id)->update(['content_hash' => str_repeat('0', 64)]);
            $before = $this->snapshots();
            try {
                app(OrderFulfillment::class)->fulfill($order->id);
                $this->fail('Damaged '.$mode.' allocation authorized a grant.');
            } catch (DomainException $error) {
                $this->assertMatchesRegularExpression('/\A(?:PAYMENT|WALLET|BILLING)_[A-Z0-9_]+\z/', $error->getMessage());
            }
            $this->assertSame($before, $this->snapshots(), $mode.' failure leaked a transaction write.');
            $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
            $this->assertSame(0, DB::table('tx_subscription_operation')->where('order_id', $order->id)->count());
        }
    }

    private function paid(): array
    {
        [$user, $order] = $this->purchase();
        return [$user, $order, $this->pay($user, $order)];
    }

    private function pay(User $user, Order $order): array
    {
        $attempt = app(NativePaymentAttempts::class)->prepare($user->id, $order->id, $this->payment->id,
            'merchant-1', 'boundary:'.$order->id);
        return app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => 'boundary-paid:'.$order->id]));
    }

    private function fund(User $user): void
    {
        $key = 'boundary-recharge:'.$user->id;
        $recharge = app(NativeRechargeService::class)->create($user->id, '5000', $this->payment->id, $key);
        $attempt = app(NativePaymentAttempts::class)->prepareRecharge($user->id, (int) $recharge['recharge_id'],
            $this->payment->id, 'merchant-1', $key);
        app(NativePaymentSettlement::class)->settle($user->id, (int) $attempt['attempt_id'],
            $this->fact($attempt, ['transaction_ref' => $key]));
    }

    private function reserve(User $user, Order $order): void
    {
        app(NativeBillingTransaction::class)->run($user->id, function (array $installation) use ($user, $order): void {
            app(NativeSubscriptionStore::class)->lockAccount($user->id, time(), $installation['installation_id']);
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $terms = OrderPurchaseTerms::query()->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            app(NativeWalletReservations::class)->reserveLocked($installation, $user->id, $locked, $terms, '500', 'port-mixed');
        });
    }

    private function snapshots(): array
    {
        $result = [];
        foreach (['tx_order', 'tx_subscription', 'tx_subscription_terms', 'tx_usage_period',
            'tx_subscription_operation', 'tx_money_account', 'tx_money_journal', 'tx_money_posting',
            'tx_order_payment', 'tx_billing_work', 'tx_native_runtime_outbox'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        return $result;
    }
}
