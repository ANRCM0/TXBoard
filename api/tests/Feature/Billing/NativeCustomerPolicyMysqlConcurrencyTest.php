<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\BillingError;
use App\Domains\Billing\NativeBillingRecovery;
use App\Domains\Billing\NativeCommissions;
use App\Domains\Billing\NativeCouponWorkflow;
use App\Domains\Billing\NativeCustomerPolicies;
use App\Domains\Billing\NativeWalletPayments;
use App\Domains\Identity\NativeReferrals;
use App\Domains\Subscription\Services\PublishedOrderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeFinancialWorkflows;
use Tests\Support\NativeMysqlWorkflowRace;
use Tests\Support\NativeRuntimeTestCase;

final class NativeCustomerPolicyMysqlConcurrencyTest extends NativeRuntimeTestCase
{
    use NativeFinancialWorkflows, NativeMysqlWorkflowRace;
    protected array $planPrices = ['monthly' => '10.00', 'onetime' => '0.00'];
    protected string $publicationCurrency = 'CNY';

    public function test_competing_policy_revisions_use_the_current_head_after_an_older_rr_snapshot(): void
    {
        $this->requireMysqlProcesses(); $this->activate();
        $admin = $this->workflowActor(true, [NativeCustomerPolicies::PERMISSION]); $token = $this->authenticateWorkflowActor($admin); $user = $this->workflowActor();
        $base = app(NativeCustomerPolicies::class)->read($admin->id, $user->id); $operations = [];
        foreach ([1000, 2500] as $bps) $operations[] = function () use ($admin, $token, $user, $base, $bps): array {
            Auth::forgetGuards(); Auth::guard('sanctum')->setUser($admin->withAccessToken($token));
            try { return app(NativeCustomerPolicies::class)->update($admin->id, $user->id, ['expected_policy_revision' => $base['policy_revision'], 'changes' => ['discount_bps' => $bps], 'reason' => 'concurrent_case'], $this->workflowKey()); }
            catch (BillingError $error) { return ['error' => $error->errorCode]; }
        };
        $results = $this->race($operations, true);
        $this->assertSame(1000, $results[0]['policy']['discount_bps']); $this->assertSame('CUSTOMER_POLICY_VERSION_CONFLICT', $results[1]['error']);
        $this->assertSame(1, DB::table('tx_native_customer_policy_revision')->count());
    }

    public function test_coupon_purchase_and_customer_policy_are_serialized_in_both_commit_orders(): void
    {
        $this->requireMysqlProcesses(); $this->activate();
        $admin = $this->workflowActor(true, [NativeCustomerPolicies::PERMISSION, 'billing.coupons.manage']); $adminToken = $this->authenticateWorkflowActor($admin);
        $this->invokeWorkflow(fn () => app(NativeCouponWorkflow::class)->execute($admin->id, 'create', ['code' => 'POLICY-RACE-01', 'name' => 'Combined policy race', 'currency' => 'CNY',
            'discount_kind' => 'fixed', 'value' => '100', 'minimum_minor' => '0', 'total_limit' => 10, 'user_limit' => 1, 'starts_at' => null, 'expires_at' => null, 'offer_ids' => []], $this->workflowKey()));
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        foreach ([true, false] as $policyFirst) {
            $buyer = $this->workflowActor(); $buyerToken = $this->authenticateWorkflowActor($buyer);
            $this->authenticateWorkflowActor($admin); $base = app(NativeCustomerPolicies::class)->read($admin->id, $buyer->id);
            $policy = function () use ($admin, $adminToken, $buyer, $base): array {
                Auth::forgetGuards(); Auth::guard('sanctum')->setUser($admin->withAccessToken($adminToken));
                return app(NativeCustomerPolicies::class)->update($admin->id, $buyer->id, ['expected_policy_revision' => $base['policy_revision'], 'changes' => ['discount_bps' => 2500], 'reason' => 'purchase_race'], $this->workflowKey());
            };
            $purchase = function () use ($buyer, $buyerToken, $offer): array {
                Auth::forgetGuards(); Auth::guard('sanctum')->setUser($buyer->withAccessToken($buyerToken));
                $order = app(PublishedOrderService::class)->create($buyer->id, (int) $offer['offer_id'], $this->workflowKey(), ['coupon_code' => 'POLICY-RACE-01']);
                return ['order_id' => (string) $order->id, 'amount_minor' => (string) $order->total_amount];
            };
            $results = $this->race($policyFirst ? [$policy, $purchase] : [$purchase, $policy], true);
            $quoted = $results[$policyFirst ? 1 : 0]; $this->assertSame($policyFirst ? '650' : '900', $quoted['amount_minor']);
            $this->assertSame(1, DB::table('tx_native_coupon_application')->where('order_id', $quoted['order_id'])->count());
            $this->assertSame($policyFirst ? 1 : 0, DB::table('tx_native_customer_price')->where('order_id', $quoted['order_id'])->count());
        }
    }

    public function test_duplicate_winning_wallet_payments_claim_first_paid_once_and_accrue_one_reward(): void
    {
        $this->requireMysqlProcesses(); $this->activate();
        $admin = $this->workflowActor(true, [NativeCustomerPolicies::PERMISSION, 'billing.commissions.manage']); $this->authenticateWorkflowActor($admin);
        $sponsor = $this->workflowActor(); $buyer = $this->workflowActor(); $policies = app(NativeCustomerPolicies::class);
        $policies->update($admin->id, $sponsor->id, ['expected_policy_revision' => $policies->read($admin->id, $sponsor->id)['policy_revision'], 'changes' => ['commission_mode' => 'first_paid', 'commission_rate_bps' => 2500], 'reason' => 'claim_race'], $this->workflowKey());
        $this->invokeWorkflow(fn () => app(NativeCommissions::class)->execute($admin->id, true, 'policy', ['enabled' => true, 'rate_bps' => 1000, 'hold_seconds' => 0, 'refund_policy' => NativeCommissions::REFUND_POLICY, 'expected_version' => '0'], $this->workflowKey()));
        $this->authenticateWorkflowActor($sponsor); $code = app(NativeReferrals::class)->execute($sponsor->id, 'code', [], $this->workflowKey());
        $buyerToken = $this->authenticateWorkflowActor($buyer); app(NativeReferrals::class)->execute($buyer->id, 'bind', ['invite_code' => $code['invite_code']], $this->workflowKey());
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly'); $order = app(PublishedOrderService::class)->create($buyer->id, (int) $offer['offer_id'], $this->workflowKey());
        $this->fundWorkflowWallet($buyer, '1000'); $key = $this->workflowKey();
        $pay = function () use ($buyer, $buyerToken, $order, $key): array {
            Auth::forgetGuards(); Auth::guard('sanctum')->setUser($buyer->withAccessToken($buyerToken));
            return app(NativeWalletPayments::class)->pay($buyer->id, $order->id, $key);
        };
        $this->race([$pay, $pay], true); app(NativeBillingRecovery::class)->recover(); app(NativeCommissions::class)->recover(10);
        $this->assertSame(1, DB::table('tx_native_first_paid_claim')->count()); $this->assertSame(1, DB::table('tx_order_payment')->count());
        $this->assertSame(1, DB::table('tx_native_commission_reward')->count()); $this->assertSame('250', $this->workflowBalance($sponsor, 'commission_available'));
    }
}
