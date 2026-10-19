<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\NativeCommissions;
use App\Domains\Identity\NativeReferrals;
use App\Domains\Subscription\Services\PublishedOrderService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeFinancialWorkflows;
use Tests\Support\NativeRuntimeTestCase;

final class NativeReferralWorkflowTest extends NativeRuntimeTestCase
{
    use NativeFinancialWorkflows;

    protected array $planPrices = ['monthly' => '10.00', 'onetime' => '0.00'];
    protected string $publicationCurrency = 'CNY';

    public function test_immutable_binding_replay_self_referral_and_different_sponsor_are_enforced(): void
    {
        $this->activate(); $referrals = app(NativeReferrals::class);
        $sponsor = $this->workflowActor(); $other = $this->workflowActor(); $buyer = $this->workflowActor();
        $this->authenticateWorkflowActor($sponsor); $key = $this->workflowKey();
        $first = $referrals->execute($sponsor->id, 'code', [], $key);
        $this->assertSame($first, $referrals->execute($sponsor->id, 'code', [], $key));
        try { $referrals->execute($sponsor->id, 'bind', ['invite_code' => $first['invite_code']], $this->workflowKey()); $this->fail('Self referral accepted'); }
        catch (\DomainException $error) { $this->assertSame('REFERRAL_UNAVAILABLE', $error->getMessage()); }
        $this->authenticateWorkflowActor($other); $second = $referrals->execute($other->id, 'code', [], $this->workflowKey());
        $this->authenticateWorkflowActor($buyer); $bindKey = $this->workflowKey(); $input = ['invite_code' => $first['invite_code']];
        $bound = $referrals->execute($buyer->id, 'bind', $input, $bindKey);
        $this->assertSame((string) $sponsor->id, $bound['sponsor_id']);
        $this->assertSame($bound, $referrals->execute($buyer->id, 'bind', $input, $bindKey));
        try { $referrals->execute($buyer->id, 'bind', ['invite_code' => $second['invite_code']], $this->workflowKey()); $this->fail('Sponsor replaced'); }
        catch (\DomainException $error) { $this->assertSame('REFERRAL_ALREADY_BOUND', $error->getMessage()); }
        $this->assertSame(1, DB::table('tx_native_referral')->count());
    }

    public function test_current_sessions_bans_and_frozen_binding_evidence_are_rechecked(): void
    {
        $this->activate(); $referrals = app(NativeReferrals::class);
        $sponsor = $this->workflowActor(); $buyer = $this->workflowActor();
        $this->authenticateWorkflowActor($sponsor); $code = $referrals->execute($sponsor->id, 'code', [], $this->workflowKey());
        $token = $this->authenticateWorkflowActor($buyer); $token->delete();
        try { $referrals->execute($buyer->id, 'bind', ['invite_code' => $code['invite_code']], $this->workflowKey()); $this->fail('Revoked session accepted'); }
        catch (AuthenticationException) { $this->assertSame(0, DB::table('tx_native_referral')->count()); }
        $this->authenticateWorkflowActor($buyer); $sponsor->forceFill(['banned' => true])->saveOrFail();
        try { $referrals->execute($buyer->id, 'bind', ['invite_code' => $code['invite_code']], $this->workflowKey()); $this->fail('Banned sponsor accepted'); }
        catch (\DomainException $error) { $this->assertSame('REFERRAL_UNAVAILABLE', $error->getMessage()); }
        $sponsor->forceFill(['banned' => false])->saveOrFail();
        $referrals->execute($buyer->id, 'bind', ['invite_code' => $code['invite_code']], $this->workflowKey());
        DB::table('tx_native_referral')->where('user_id', $buyer->id)->update(['content_hash' => str_repeat('0', 64)]);
        try { $referrals->read($buyer->id); $this->fail('Damaged referral disclosed'); }
        catch (\LogicException $error) { $this->assertSame('REFERRAL_EVIDENCE_INVALID', $error->getMessage()); }
    }

    public function test_binding_after_an_order_does_not_retroactively_add_commission_terms(): void
    {
        $this->activate(); $admin = $this->workflowActor(true, ['billing.commissions.manage']);
        $this->authenticateWorkflowActor($admin);
        $this->invokeWorkflow(fn () => app(NativeCommissions::class)->execute($admin->id, true, 'policy',
            ['enabled' => true, 'rate_bps' => 1000, 'hold_seconds' => 0, 'refund_policy' => NativeCommissions::REFUND_POLICY, 'expected_version' => '0'], $this->workflowKey()));
        $sponsor = $this->workflowActor(); $buyer = $this->workflowActor(); $referrals = app(NativeReferrals::class);
        $this->authenticateWorkflowActor($sponsor); $code = $referrals->execute($sponsor->id, 'code', [], $this->workflowKey());
        $this->authenticateWorkflowActor($buyer); $key = $this->workflowKey(); $offer = collect($this->offers)->firstWhere('offer_code', 'monthly');
        $order = app(PublishedOrderService::class)->create($buyer->id, (int) $offer['offer_id'], $key);
        $referrals->execute($buyer->id, 'bind', ['invite_code' => $code['invite_code']], $this->workflowKey());
        $this->assertSame($order->id, app(PublishedOrderService::class)->create($buyer->id, (int) $offer['offer_id'], $key)->id);
        $this->assertSame(0, DB::table('tx_native_commission_order')->count());
    }
}
