<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\BillingError;
use App\Domains\Billing\MoneyValue;
use App\Domains\Billing\NativeGiftCards;
use App\Domains\Billing\NativeMoneyLedger;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NativeRuntimeTestCase;

/** Deferred acceptance using production migrations, native admission, sessions and wallet evidence. */
final class NativeGiftBatchesTest extends NativeRuntimeTestCase
{
    private NativeGiftCards $gifts;

    protected function setUp(): void
    {
        parent::setUp(); $this->activate(); $this->gifts = app(NativeGiftCards::class);
    }

    public function test_wallet_funded_batch_replay_preserves_codes_but_reads_current_terminal_states(): void
    {
        $issuer = $this->actor(); $recipient = $this->actor(); $this->fund($issuer, '1000'); $this->authenticateActor($issuer);
        $input = $this->input(); $key = $this->key(); $issued = $this->gifts->issueBatch($issuer->id, $input, $key);
        $this->assertSame('300', $issued['batch']['total_minor']);
        $this->assertSame('700', $this->balance($issuer)); $this->assertSame('300', $this->balance($issuer, 'wallet_reserved'));
        $this->assertSame(3, DB::table('tx_money_journal')->where('business_type', 'gift_card_reserve')->count());
        $this->authenticateActor($recipient);
        $this->gifts->execute($recipient->id, 'redeem', ['code' => $issued['cards'][0]['code']], $this->key());
        $this->assertNull($this->gifts->batch($recipient->id, $issued['batch']['batch_id']));
        $this->assertSame([], $this->gifts->batches($recipient->id, false, 1, 20)->items);
        $this->authenticateActor($issuer);
        $this->gifts->execute($issuer->id, 'revoke', ['card_id' => $issued['cards'][1]['card_id'], 'expected_version' => '1'], $this->key());
        $replay = $this->gifts->issueBatch($issuer->id, array_reverse($input, true), $key);
        $this->assertSame(['redeemed', 'revoked', 'reserved'], array_column($replay['cards'], 'state'));
        $this->assertSame(array_column($issued['cards'], 'code'), array_column($replay['cards'], 'code'));
        $this->assertSame('800', $this->balance($issuer)); $this->assertSame('100', $this->balance($recipient));
        $read = $this->gifts->batch($issuer->id, $issued['batch']['batch_id']);
        foreach ($read['cards'] as $card) $this->assertArrayNotHasKey('code', $card);
        $this->assertSame(1, $this->gifts->batches($issuer->id, false, 1, 20)->meta['total']);
        $input['label'] = 'Changed label';
        $this->billingRejects('GIFT_CARD_IDEMPOTENCY_CONFLICT', fn () => $this->gifts->issueBatch($issuer->id, $input, $key));
    }

    public function test_insufficient_wallet_rolls_back_every_card_and_partial_reservation(): void
    {
        $issuer = $this->actor(); $this->fund($issuer, '250'); $this->authenticateActor($issuer);
        $events = DB::table('tx_module_event')->count();
        $this->billingRejects('BILLING_INSUFFICIENT_FUNDS', fn () => $this->gifts->issueBatch($issuer->id, $this->input(), $this->key()));
        $this->assertSame('250', $this->balance($issuer));
        $this->assertSame(0, DB::table('tx_native_gift_batch')->count());
        $this->assertSame(0, DB::table('tx_native_gift_card')->count());
        $this->assertSame(0, DB::table('tx_money_journal')->where('business_type', 'gift_card_reserve')->count());
        $this->assertSame(0, DB::table('tx_native_gift_request')->count());
        $this->assertSame($events, DB::table('tx_module_event')->count());
    }

    public function test_late_receipt_failure_rolls_back_events_and_money_and_original_key_can_retry(): void
    {
        $issuer = $this->actor(); $this->fund($issuer, '1000'); $this->authenticateActor($issuer);
        $key = $this->key(); $events = DB::table('tx_module_event')->count(); $fail = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$fail): void {
            if ($fail && preg_match('/insert into ["`]tx_native_gift_request["`]/i', $sql)) {
                $fail = false; throw new \RuntimeException('INJECTED_GIFT_RECEIPT_FAILURE');
            }
        });
        try { $this->gifts->issueBatch($issuer->id, $this->input(), $key); $this->fail('Expected receipt failure'); }
        catch (\RuntimeException $error) { $this->assertSame('INJECTED_GIFT_RECEIPT_FAILURE', $error->getMessage()); }
        $this->assertSame('1000', $this->balance($issuer));
        $this->assertSame(0, DB::table('tx_native_gift_card')->count());
        $this->assertSame(0, DB::table('tx_native_gift_batch')->count());
        $this->assertSame($events, DB::table('tx_module_event')->count());
        $issued = $this->gifts->issueBatch($issuer->id, $this->input(), $key);
        $this->assertCount(3, $issued['cards']); $this->assertSame('700', $this->balance($issuer));
    }

    public function test_fifty_card_receipt_fits_text_and_neither_reads_nor_events_reveal_codes(): void
    {
        $issuer = $this->actor(); $this->fund($issuer, '5000'); $this->authenticateActor($issuer);
        $input = $this->input(); $input['quantity'] = 50; $input['label'] = str_repeat('Q', 80);
        $issued = $this->gifts->issueBatch($issuer->id, $input, $this->key());
        $this->assertCount(50, $issued['cards']);
        $this->assertLessThanOrEqual(60000, strlen(DB::table('tx_native_gift_request')->where('action', 'issue-batch')->value('response_cipher')));
        $visible = json_encode([$this->gifts->batch($issuer->id, $issued['batch']['batch_id']),
            $this->gifts->batches($issuer->id, false, 1, 50)->items,
            DB::table('tx_module_event')->pluck('body')->all()], JSON_THROW_ON_ERROR);
        foreach ($issued['cards'] as $card) $this->assertStringNotContainsString($card['code'], $visible);
        $this->assertSame('0', $this->balance($issuer));
    }

    public function test_batch_membership_and_journal_evidence_are_checked_after_valid_looking_parent_reseal(): void
    {
        $issuer = $this->actor(); $this->fund($issuer, '1000'); $this->authenticateActor($issuer);
        $issued = $this->gifts->issueBatch($issuer->id, $this->input(), $this->key());
        $row = DB::table('tx_native_gift_batch')->where('id', $issued['batch']['batch_id'])->first();
        $terms = json_decode($row->terms_json, true, 32, JSON_THROW_ON_ERROR);
        [$terms['card_ids'][0], $terms['card_ids'][1]] = [$terms['card_ids'][1], $terms['card_ids'][0]];
        DB::table('tx_native_gift_batch')->where('id', $row->id)->update(['terms_json' => json_encode($terms, JSON_THROW_ON_ERROR),
            'terms_hash' => MoneyValue::hash($terms)]);
        try { $this->gifts->batch($issuer->id, $row->id); $this->fail('Ordinal mismatch was accepted'); }
        catch (\LogicException $error) { $this->assertSame('GIFT_BATCH_EVIDENCE_INVALID', $error->getMessage()); }
        DB::table('tx_native_gift_batch')->where('id', $row->id)->update(['terms_json' => $row->terms_json, 'terms_hash' => $row->terms_hash]);
        $reserve = DB::table('tx_native_gift_card')->where('id', $issued['cards'][0]['card_id'])->value('reserve_journal_id');
        DB::table('tx_money_journal')->where('id', $reserve)->update(['business_ref' => 'wrong-card']);
        try { $this->gifts->batch($issuer->id, $row->id); $this->fail('Damaged reservation evidence was accepted'); }
        catch (BillingError|\LogicException) { $this->assertTrue(true); }
    }

    public function test_legacy_single_card_terms_stay_compatible_and_batch_rejects_invalid_total_before_writes(): void
    {
        $issuer = $this->actor(); $this->fund($issuer, '1000'); $this->authenticateActor($issuer);
        $input = $this->input(); unset($input['label'], $input['quantity']);
        $card = $this->gifts->execute($issuer->id, 'issue', $input, $this->key());
        $row = DB::table('tx_native_gift_card')->where('id', $card['card_id'])->first();
        $this->assertNull($row->batch_id);
        $this->assertSame('native-gift-card/1', json_decode($row->terms_json, true)['schema']);
        $this->gifts->execute($issuer->id, 'revoke', ['card_id' => $card['card_id'], 'expected_version' => '1'], $this->key());
        $this->assertSame('1000', $this->balance($issuer));
        $invalid = $this->input(); $invalid['quantity'] = 2; $invalid['amount_minor'] = '2147483647';
        try { $this->gifts->issueBatch($issuer->id, $invalid, $this->key()); $this->fail('Oversized total was accepted'); }
        catch (\InvalidArgumentException $error) { $this->assertSame('GIFT_CARD_INPUT_INVALID', $error->getMessage()); }
        $this->assertSame(0, DB::table('tx_native_gift_batch')->count());
    }

    private function actor(): User
    {
        return User::withoutEvents(fn () => User::query()->create(['email' => $this->key().'@example.test',
            'password' => 'fixture-only', 'token' => '', 'uuid' => $this->key(), 'banned' => false, 'is_admin' => false]));
    }

    private function authenticateActor(User $user): void
    {
        $token = $user->createToken('gift-batch-owner', ['*']); Auth::forgetGuards();
        Auth::guard('sanctum')->setUser($user->withAccessToken($token->accessToken));
    }

    private function fund(User $user, string $amount): void
    {
        app(NativeMoneyLedger::class)->post($user->id, ['business_type' => 'wallet_credit', 'business_ref' => 'batch-fixture',
            'idempotency_key' => $this->key(), 'currency' => 'CNY', 'occurred_at' => (string) time(), 'postings' => [
                ['owner_id' => '0', 'purpose' => 'provider_clearing', 'side' => 'debit', 'amount_minor' => $amount],
                ['owner_id' => (string) $user->id, 'purpose' => 'wallet_available', 'side' => 'credit', 'amount_minor' => $amount]]]);
    }

    private function balance(User $user, string $purpose = 'wallet_available'): string
    {
        return (string) (DB::table('tx_money_account')->where('owner_id', $user->id)->where('purpose', $purpose)->where('currency', 'CNY')->value('balance_minor') ?? '0');
    }

    private function input(): array
    {
        return ['label' => 'Wallet-funded fixture', 'amount_minor' => '100', 'currency' => 'CNY', 'quantity' => 3, 'expires_at' => time() + 86400];
    }

    private function billingRejects(string $code, \Closure $action): void
    {
        try { $action(); $this->fail('Expected '.$code); }
        catch (BillingError $error) { $this->assertSame($code, $error->errorCode); }
    }

    private function key(): string { return (string) Str::uuid(); }
}
