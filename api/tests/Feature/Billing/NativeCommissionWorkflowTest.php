<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\NativeBillingRecovery;
use App\Domains\Billing\NativeCommissions;
use App\Domains\Billing\NativeOrderLifecycle;
use App\Domains\Billing\NativeRefundService;
use App\Domains\Billing\NativeWalletPayments;
use App\Domains\Identity\NativeReferrals;
use App\Domains\InternalApi\OperationSchemas;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeFinancialWorkflows;
use Tests\Support\NativeRuntimeTestCase;

final class NativeCommissionWorkflowTest extends NativeRuntimeTestCase
{
    use NativeFinancialWorkflows;

    protected array $planPrices = ['monthly' => '10.00', 'onetime' => '0.00'];
    protected string $publicationCurrency = 'CNY';
    private NativeCommissions $commissions;
    private User $admin;
    private User $sponsor;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp(); $this->activate(); $this->commissions = app(NativeCommissions::class);
        $this->admin = $this->workflowActor(true, ['billing.commissions.manage', 'billing.commissions.read']);
        $this->authenticateWorkflowActor($this->admin); $this->changePolicy('0');
        $this->sponsor = $this->workflowActor(); $this->buyer = $this->workflowActor();
        $this->authenticateWorkflowActor($this->sponsor);
        $code = app(NativeReferrals::class)->execute($this->sponsor->id, 'code', [], $this->workflowKey());
        $this->authenticateWorkflowActor($this->buyer);
        app(NativeReferrals::class)->execute($this->buyer->id, 'bind', ['invite_code' => $code['invite_code']], $this->workflowKey());
    }

    public function test_frozen_rate_only_accrues_after_real_payment_and_recovery_posts_once(): void
    {
        $order = $this->purchase();
        $this->assertSame([], $this->commissions->recover(10));
        $this->assertSame('0', $this->workflowBalance($this->sponsor, 'commission_available'));
        $this->authenticateWorkflowActor($this->admin); $this->changePolicy('1', ['rate_bps' => 5000]);
        $this->pay($order);
        $results = $this->commissions->recover(10); $reward = array_values($results)[0];
        $this->assertSame('accrued', $reward['state']); $this->assertSame('100', $reward['amount_minor']);
        $this->assertSame(1000, $reward['rate_bps']);
        $this->assertSame([], $this->commissions->recover(10));
        $this->assertSame(1, DB::table('tx_native_commission_reward')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->where('business_type', 'commission_accrue')->count());
        $this->authenticateWorkflowActor($this->sponsor);
        $rows = $this->commissions->listing($this->sponsor->id, false, 'rewards', 1, 20);
        $this->assertSame([$reward], $rows->items);
        OperationSchemas::output('billing.commissions.rewards.list', $rows->items);
        $this->authenticateWorkflowActor($this->buyer);
        $this->assertSame([], $this->commissions->listing($this->buyer->id, false, 'rewards', 1, 20)->items);
    }

    public function test_refunds_before_vesting_reduce_net_and_refunds_after_vesting_keep_declared_policy(): void
    {
        $order = $this->purchase(); $payment = $this->pay($order);
        $refunds = app(NativeRefundService::class);
        $refunds->refundToWallet($this->buyer->id, 'wallet_payment', (int) $payment['payment']['order_payment_id'],
            '400', $this->workflowKey(), $this->admin->id, 'before_vesting');
        $reward = array_values($this->commissions->recover(10))[0];
        $this->assertSame('60', $reward['amount_minor']);
        $this->assertSame(NativeCommissions::REFUND_POLICY, $reward['refund_policy']);
        $refunds->refundToWallet($this->buyer->id, 'wallet_payment', (int) $payment['payment']['order_payment_id'],
            '100', $this->workflowKey(), $this->admin->id, 'after_vesting');
        $this->assertSame([], $this->commissions->recover(10));
        $this->assertSame('60', $this->workflowBalance($this->sponsor, 'commission_available'));
    }

    public function test_cancelled_orders_and_full_refunds_do_not_create_commission_money(): void
    {
        $order = $this->purchase(); (new NativeOrderLifecycle($order))->cancel();
        $reward = array_values($this->commissions->recover(10))[0];
        $this->assertSame('ineligible', $reward['state']); $this->assertSame('0', $reward['amount_minor']);
        $order = $this->purchase(); $payment = $this->pay($order);
        app(NativeRefundService::class)->refundToWallet($this->buyer->id, 'wallet_payment',
            (int) $payment['payment']['order_payment_id'], '1000', $this->workflowKey(), $this->admin->id, 'full_refund');
        $reward = array_values($this->commissions->recover(10))[0];
        $this->assertSame('ineligible', $reward['state']);
        $this->assertSame(0, DB::table('tx_money_journal')->where('business_type', 'commission_accrue')->count());
    }

    public function test_reviewed_payouts_reserve_approve_or_release_exactly_once_and_recheck_authority(): void
    {
        $this->pay($this->purchase()); $this->commissions->recover(10);
        $this->authenticateWorkflowActor($this->sponsor);
        $key = $this->workflowKey(); $request = ['currency' => 'CNY', 'amount_minor' => '40'];
        $payout = $this->command($this->sponsor, false, 'payout', $request, $key);
        $this->assertSame($payout, $this->command($this->sponsor, false, 'payout', $request, $key));
        $this->assertSame('60', $this->workflowBalance($this->sponsor, 'commission_available'));
        $this->assertSame('40', $this->workflowBalance($this->sponsor, 'commission_reserved'));
        $this->workflowBillingFailure('COMMISSION_IDEMPOTENCY_CONFLICT', fn () => $this->command($this->sponsor,
            false, 'payout', ['currency' => 'CNY', 'amount_minor' => '41'], $key));
        $this->authenticateWorkflowActor($this->admin);
        $input = ['payout_id' => $payout['id'], 'expected_version' => '1', 'reason' => 'reviewed']; $decisionKey = $this->workflowKey();
        $approved = $this->command($this->admin, true, 'approve', $input, $decisionKey);
        $this->assertSame('approved', $approved['state']);
        $this->assertSame($approved, $this->command($this->admin, true, 'approve', $input, $decisionKey));
        $this->workflowBillingFailure('COMMISSION_VERSION_CONFLICT', fn () => $this->command($this->admin, true, 'reject', $input));
        DB::table('tx_internal_actor_grant')->where('user_id', $this->admin->id)->where('permission', 'billing.commissions.manage')->delete();
        $this->workflowHttpFailure(403, fn () => $this->command($this->admin, true, 'approve', $input, $decisionKey));
        $this->assertSame('40', $this->workflowBalance($this->sponsor));
        $this->assertSame('0', $this->workflowBalance($this->sponsor, 'commission_reserved'));
        $this->authenticateWorkflowActor($this->sponsor);
        $rejected = $this->command($this->sponsor, false, 'payout', ['currency' => 'CNY', 'amount_minor' => '60']);
        DB::table('tx_internal_actor_grant')->insert(['user_id' => $this->admin->id, 'permission' => 'billing.commissions.manage', 'created_at' => time()]);
        $this->authenticateWorkflowActor($this->admin);
        $this->command($this->admin, true, 'reject', ['payout_id' => $rejected['id'], 'expected_version' => '1', 'reason' => 'declined']);
        $this->assertSame('60', $this->workflowBalance($this->sponsor, 'commission_available'));
        $this->assertSame('0', $this->workflowBalance($this->sponsor, 'commission_reserved'));
        $this->assertSame(1, DB::table('tx_money_journal')->where('business_type', 'commission_approve')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->where('business_type', 'commission_reject')->count());
    }

    public function test_late_decision_failure_rolls_back_money_and_corrupt_payout_reads_fail_closed(): void
    {
        $this->pay($this->purchase()); $this->commissions->recover(10);
        $this->authenticateWorkflowActor($this->sponsor);
        $payout = $this->command($this->sponsor, false, 'payout', ['currency' => 'CNY', 'amount_minor' => '100']);
        $this->authenticateWorkflowActor($this->admin); $fail = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$fail): void {
            if ($fail && preg_match('/insert into ["`]tx_native_commission_request["`]/i', $sql)) {
                $fail = false; throw new \RuntimeException('INJECTED_COMMISSION_RECEIPT');
            }
        });
        $key = $this->workflowKey(); $input = ['payout_id' => $payout['id'], 'expected_version' => '1', 'reason' => 'reviewed'];
        try { $this->command($this->admin, true, 'approve', $input, $key); $this->fail('Expected late failure'); }
        catch (\RuntimeException $error) { $this->assertSame('INJECTED_COMMISSION_RECEIPT', $error->getMessage()); }
        $this->assertSame('requested', DB::table('tx_native_commission_payout')->value('state'));
        $this->assertSame('100', $this->workflowBalance($this->sponsor, 'commission_reserved'));
        $this->assertSame('0', $this->workflowBalance($this->sponsor));
        $this->assertSame(0, DB::table('tx_native_commission_payout_terminal')->count());
        $this->command($this->admin, true, 'approve', $input, $key);
        DB::table('tx_native_commission_payout')->where('id', $payout['id'])->update(['reason' => 'tampered']);
        try { $this->commissions->listing($this->admin->id, true, 'payouts', 1, 20); $this->fail('Corrupt payout disclosed'); }
        catch (\LogicException $error) { $this->assertSame('COMMISSION_PAYOUT_INVALID', $error->getMessage()); }
        $this->assertSame('100', $this->workflowBalance($this->sponsor));
    }

    private function changePolicy(string $version, array $changes = []): array
    {
        return $this->command($this->admin, true, 'policy', array_replace(['enabled' => true, 'rate_bps' => 1000,
            'hold_seconds' => 0, 'refund_policy' => NativeCommissions::REFUND_POLICY, 'expected_version' => $version], $changes));
    }

    private function command(User $actor, bool $admin, string $action, array $input, ?string $key = null): array
    {
        return $this->invokeWorkflow(fn () => $this->commissions->execute($actor->id, $admin, $action, $input, $key ?? $this->workflowKey()));
    }

    private function purchase(): Order
    {
        $this->authenticateWorkflowActor($this->buyer);
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        return app(PublishedOrderService::class)->create($this->buyer->id, (int) $offer['offer_id'], $this->workflowKey());
    }

    private function pay(Order $order): array
    {
        $this->fundWorkflowWallet($this->buyer, '1000');
        $payment = app(NativeWalletPayments::class)->pay($this->buyer->id, $order->id, $this->workflowKey());
        app(NativeBillingRecovery::class)->recover();
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        return $payment;
    }
}
