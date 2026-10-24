<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\NativeGiftCards;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeFinancialWorkflows;
use Tests\Support\NativeRuntimeTestCase;

final class NativeGiftCardWorkflowTest extends NativeRuntimeTestCase
{
    use NativeFinancialWorkflows;

    public function test_code_redemption_response_loss_replay_and_unrelated_actor_reads_are_scoped(): void
    {
        $this->activate(); $gifts = app(NativeGiftCards::class);
        $issuer = $this->workflowActor(); $recipient = $this->workflowActor(); $stranger = $this->workflowActor();
        $this->fundWorkflowWallet($issuer, '1000'); $this->authenticateWorkflowActor($issuer);
        $key = $this->workflowKey(); $input = ['currency' => 'CNY', 'amount_minor' => '300', 'expires_at' => time() + 3600];
        $issued = $this->invokeWorkflow(fn () => $gifts->execute($issuer->id, 'issue', $input, $key));
        $this->assertSame($issued, $gifts->execute($issuer->id, 'issue', $input, $key));
        $this->workflowBillingFailure('GIFT_CARD_SELF_REDEEM', fn () => $gifts->execute($issuer->id, 'redeem', ['code' => $issued['code']], $this->workflowKey()));
        $this->authenticateWorkflowActor($recipient); $redeemKey = $this->workflowKey();
        $redeemed = $gifts->execute($recipient->id, 'redeem', ['code' => $issued['code']], $redeemKey);
        $this->assertSame($redeemed, $gifts->execute($recipient->id, 'redeem', ['code' => $issued['code']], $redeemKey));
        $this->assertSame('300', $this->workflowBalance($recipient));
        $this->assertSame('700', $this->workflowBalance($issuer));
        $this->assertSame(1, DB::table('tx_money_journal')->where('business_type', 'gift_card_credit')->count());
        $this->assertArrayNotHasKey('code', $gifts->listing($recipient->id, false, 1, 20)->items[0]);
        $this->authenticateWorkflowActor($stranger);
        $this->assertSame([], $gifts->listing($stranger->id, false, 1, 20)->items);
        $this->workflowBillingFailure('GIFT_CARD_UNAVAILABLE', fn () => $gifts->execute($stranger->id, 'redeem', ['code' => $issued['code']], $this->workflowKey()));
        $this->assertStringNotContainsString($issued['code'], DB::table('tx_native_gift_request')->where('action', 'issue')->value('response_cipher'));
        $token = $this->authenticateWorkflowActor($issuer); $token->delete();
        try { $gifts->execute($issuer->id, 'issue', $input, $key); $this->fail('Revoked session replay accepted'); }
        catch (AuthenticationException) { $this->assertSame(1, DB::table('tx_native_gift_card')->count()); }
    }

    public function test_expiry_releases_reservation_once_and_read_verifies_terminal_evidence(): void
    {
        $this->activate(); $gifts = app(NativeGiftCards::class); $issuer = $this->workflowActor();
        $this->fundWorkflowWallet($issuer, '1000'); $this->authenticateWorkflowActor($issuer);
        $expires = time() + 5;
        $card = $gifts->execute($issuer->id, 'issue', ['currency' => 'CNY', 'amount_minor' => '100', 'expires_at' => $expires], $this->workflowKey());
        // The domain compares expires_at against its own time(), taken inside the
        // call, so a fixed usleep() races with the runner: too short and the card
        // is not yet expired, too long only wastes time. Wait until the minted
        // expiry has actually passed.
        $wait = $expires - time() + 1;
        if ($wait > 0) usleep($wait * 1000000);
        $results = $gifts->expire(10);
        $this->assertSame(['scanned' => 1, 'expired' => 1, 'errors' => []], $results);
        $this->assertSame(['scanned' => 0, 'expired' => 0, 'errors' => []], $gifts->expire(10));
        $this->assertSame('1000', $this->workflowBalance($issuer));
        $this->assertSame('0', $this->workflowBalance($issuer, 'wallet_reserved'));
        $this->assertSame(1, DB::table('tx_money_journal')->where('business_type', 'gift_card_release')->count());
        DB::table('tx_native_gift_terminal')->where('card_id', $card['card_id'])->update(['content_hash' => str_repeat('0', 64)]);
        try { $gifts->listing($issuer->id, false, 1, 20); $this->fail('Damaged terminal disclosed'); }
        catch (\LogicException $error) { $this->assertSame('GIFT_CARD_EVIDENCE_INVALID', $error->getMessage()); }
    }
}
