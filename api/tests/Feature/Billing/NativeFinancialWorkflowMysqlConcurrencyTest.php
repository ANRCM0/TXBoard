<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\BillingError;
use App\Domains\Billing\NativeBillingRecovery;
use App\Domains\Billing\NativeCommissions;
use App\Domains\Billing\NativeCouponWorkflow;
use App\Domains\Billing\NativeGiftCards;
use App\Domains\Billing\NativeWalletPayments;
use App\Domains\Identity\NativeReferrals;
use App\Domains\Subscription\Services\PublishedOrderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeFinancialWorkflows;
use Tests\Support\NativeMysqlWorkflowRace;
use Tests\Support\NativeRuntimeTestCase;

final class NativeFinancialWorkflowMysqlConcurrencyTest extends NativeRuntimeTestCase
{
    use NativeFinancialWorkflows, NativeMysqlWorkflowRace;

    protected array $planPrices = ['monthly' => '10.00', 'onetime' => '0.00'];
    protected string $publicationCurrency = 'CNY';

    public function test_two_accounts_cannot_consume_the_last_coupon_use_after_an_older_rr_snapshot(): void
    {
        $this->requireMysqlProcesses(); $this->activate();
        $admin = $this->workflowActor(true, ['billing.coupons.manage']); $this->authenticateWorkflowActor($admin);
        $this->invokeWorkflow(fn () => app(NativeCouponWorkflow::class)->execute($admin->id, 'create', [
            'code' => 'LAST-USE-01', 'name' => 'Last use', 'currency' => 'CNY', 'discount_kind' => 'fixed',
            'value' => '100', 'minimum_minor' => '0', 'total_limit' => 1, 'user_limit' => 1,
            'starts_at' => null, 'expires_at' => null, 'offer_ids' => [],
        ], $this->workflowKey()));
        $first = $this->workflowActor(); $second = $this->workflowActor();
        $firstToken = $this->authenticateWorkflowActor($first); $secondToken = $this->authenticateWorkflowActor($second);
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        $operations = [];
        foreach ([[$first, $firstToken], [$second, $secondToken]] as [$user, $token]) $operations[] = function () use ($user, $token, $offer): array {
            Auth::forgetGuards(); Auth::guard('sanctum')->setUser($user->withAccessToken($token));
            try { $order = app(PublishedOrderService::class)->create($user->id, (int) $offer['offer_id'], $this->workflowKey(), ['coupon_code' => 'LAST-USE-01']); return ['amount' => (string) $order->total_amount]; }
            catch (BillingError $error) { return ['error' => $error->errorCode]; }
        };
        $results = $this->race($operations, true);
        $this->assertSame('900', $results[0]['amount']); $this->assertSame('COUPON_USAGE_EXHAUSTED', $results[1]['error']);
        $this->assertSame(1, DB::table('tx_native_coupon_application')->count());
        $this->assertSame(1, (int) DB::table('tx_native_coupon')->value('used_total'));
    }

    public function test_two_recipients_cannot_redeem_one_reserved_card_twice(): void
    {
        $this->requireMysqlProcesses(); $this->activate();
        $issuer = $this->workflowActor(); $this->fundWorkflowWallet($issuer, '500'); $this->authenticateWorkflowActor($issuer);
        $card = app(NativeGiftCards::class)->execute($issuer->id, 'issue', ['currency' => 'CNY', 'amount_minor' => '300', 'expires_at' => time() + 3600], $this->workflowKey());
        $first = $this->workflowActor(); $second = $this->workflowActor();
        $operations = [];
        foreach ([$first, $second] as $user) {
            $token = $this->authenticateWorkflowActor($user);
            $operations[] = function () use ($user, $token, $card): array {
                Auth::forgetGuards(); Auth::guard('sanctum')->setUser($user->withAccessToken($token));
                try { return app(NativeGiftCards::class)->execute($user->id, 'redeem', ['code' => $card['code']], $this->workflowKey()); }
                catch (BillingError $error) { return ['error' => $error->errorCode]; }
            };
        }
        $results = $this->race($operations, true);
        $this->assertSame('redeemed', $results[0]['state']); $this->assertSame('GIFT_CARD_UNAVAILABLE', $results[1]['error']);
        $this->assertSame('300', $this->workflowBalance($first)); $this->assertSame('0', $this->workflowBalance($second));
        $this->assertSame('200', $this->workflowBalance($issuer)); $this->assertSame('0', $this->workflowBalance($issuer, 'wallet_reserved'));
        $this->assertSame(1, DB::table('tx_money_journal')->where('business_type', 'gift_card_credit')->count());
        $this->assertSame(1, DB::table('tx_native_gift_terminal')->count());
    }

    public function test_competing_payout_decisions_cannot_both_spend_the_same_commission_reservation(): void
    {
        $this->requireMysqlProcesses(); $this->activate();
        $admin = $this->workflowActor(true, ['billing.commissions.manage']); $token = $this->authenticateWorkflowActor($admin);
        $this->invokeWorkflow(fn () => app(NativeCommissions::class)->execute($admin->id, true, 'policy', [
            'enabled' => true, 'rate_bps' => 1000, 'hold_seconds' => 0,
            'refund_policy' => NativeCommissions::REFUND_POLICY, 'expected_version' => '0',
        ], $this->workflowKey()));
        $sponsor = $this->workflowActor(); $buyer = $this->workflowActor(); $this->authenticateWorkflowActor($sponsor);
        $code = app(NativeReferrals::class)->execute($sponsor->id, 'code', [], $this->workflowKey());
        $this->authenticateWorkflowActor($buyer);
        app(NativeReferrals::class)->execute($buyer->id, 'bind', ['invite_code' => $code['invite_code']], $this->workflowKey());
        $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        $order = app(PublishedOrderService::class)->create($buyer->id, (int) $offer['offer_id'], $this->workflowKey());
        $this->fundWorkflowWallet($buyer, '1000');
        app(NativeWalletPayments::class)->pay($buyer->id, $order->id, $this->workflowKey());
        app(NativeBillingRecovery::class)->recover(); app(NativeCommissions::class)->recover(10);
        $this->authenticateWorkflowActor($sponsor);
        $payout = $this->invokeWorkflow(fn () => app(NativeCommissions::class)->execute($sponsor->id, false, 'payout', ['currency' => 'CNY', 'amount_minor' => '100'], $this->workflowKey()));
        $input = ['payout_id' => $payout['id'], 'expected_version' => '1', 'reason' => 'reviewed'];
        $operations = [];
        foreach (['approve', 'reject'] as $action) $operations[] = function () use ($admin, $token, $input, $action): array {
            Auth::forgetGuards(); Auth::guard('sanctum')->setUser($admin->withAccessToken($token));
            try { return $this->invokeWorkflow(fn () => app(NativeCommissions::class)->execute($admin->id, true, $action, $input, $this->workflowKey())); }
            catch (BillingError $error) { return ['error' => $error->errorCode]; }
        };
        $results = $this->race($operations, true);
        $this->assertSame('approved', $results[0]['state']); $this->assertSame('COMMISSION_VERSION_CONFLICT', $results[1]['error']);
        $this->assertSame('100', $this->workflowBalance($sponsor));
        $this->assertSame('0', $this->workflowBalance($sponsor, 'commission_available'));
        $this->assertSame('0', $this->workflowBalance($sponsor, 'commission_reserved'));
        $this->assertSame(1, DB::table('tx_native_commission_payout_terminal')->count());
        $this->assertSame(0, DB::table('tx_money_journal')->where('business_type', 'commission_reject')->count());
    }
}
