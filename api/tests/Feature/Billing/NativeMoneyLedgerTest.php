<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\BillingError;
use App\Domains\Billing\MoneyValue;
use App\Domains\Billing\NativeMoneyLedger;
use App\Services\Auth\NativeIdentityService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\NativeRuntimeTestCase;

final class NativeMoneyLedgerTest extends NativeRuntimeTestCase
{
    public function test_balanced_credit_and_permanent_replay_do_not_repeat_balance_mutations(): void
    {
        $user = $this->account();
        $request = $this->credit($user, '100');
        $ledger = app(NativeMoneyLedger::class);
        $journal = $ledger->post($user, $request);
        DB::table('tx_money_journal')->update(['created_at' => time() - 20 * 365 * 86400]);
        $this->assertSame($journal, $ledger->post($user, $request));
        $this->assertSame('100', $this->balance($user));
        $this->assertSame('100', $this->balance(0, 'provider_clearing'));
        $this->assertSame(1, DB::table('tx_money_journal')->count());
        $this->assertSame(2, DB::table('tx_money_posting')->count());
        $this->assertSame(0, (int) DB::table('tx_user')->where('id', $user)->value('balance'));
        $request['occurred_at']++;
        $this->reject('BILLING_IDEMPOTENCY_CONFLICT', fn () => $ledger->post($user, $request));
        $this->assertSame('100', $this->balance($user));
    }

    public function test_reserve_and_capture_use_separate_wallet_projections(): void
    {
        $user = $this->account();
        $ledger = app(NativeMoneyLedger::class);
        $ledger->post($user, $this->credit($user, '100'));
        $request = $this->credit($user, '30');
        $request['business_type'] = 'wallet_reserve';
        $request['postings'] = [
            ['owner_id' => $user, 'purpose' => 'wallet_available', 'side' => 'debit', 'amount_minor' => '30'],
            ['owner_id' => $user, 'purpose' => 'wallet_reserved', 'side' => 'credit', 'amount_minor' => '30'],
        ];
        $ledger->post($user, $request);
        $this->assertSame('70', $this->balance($user));
        $this->assertSame('30', $this->balance($user, 'wallet_reserved'));
        $request['business_type'] = 'wallet_capture';
        $request['postings'] = [
            ['owner_id' => $user, 'purpose' => 'wallet_reserved', 'side' => 'debit', 'amount_minor' => '30'],
            ['owner_id' => '0', 'purpose' => 'sales_revenue', 'side' => 'credit', 'amount_minor' => '30'],
        ];
        $ledger->post($user, $request);
        $this->assertSame('0', $this->balance($user, 'wallet_reserved'));
        $this->assertSame('30', $this->balance(0, 'sales_revenue'));
    }

    public function test_reversal_is_exact_append_only_and_original_replay_stays_reversed(): void
    {
        $user = $this->account();
        $ledger = app(NativeMoneyLedger::class);
        $request = $this->credit($user, '100');
        $original = $ledger->post($user, $request);
        $reversed = $ledger->reverse($user, (int) $original['journal_id'], 'reverse', 1700000001);
        $this->assertSame($reversed, $ledger->reverse($user, (int) $original['journal_id'], 'reverse', 1700000001));
        $this->assertSame($original, $ledger->post($user, $request));
        $this->assertSame('0', $this->balance($user));
        $this->assertSame('0', $this->balance(0, 'provider_clearing'));
        $this->assertSame($original['journal_id'], $reversed['reverses_journal_id']);
        $originalRows = DB::table('tx_money_posting')->where('journal_id', $original['journal_id'])->orderBy('position')->get();
        $reversalRows = DB::table('tx_money_posting')->where('journal_id', $reversed['journal_id'])->orderBy('position')->get();
        foreach ($originalRows as $position => $row) {
            $inverse = $reversalRows[$position];
            $this->assertSame((string) $row->account_id, (string) $inverse->account_id);
            $this->assertSame((string) $row->amount_minor, (string) $inverse->amount_minor);
            $this->assertSame($row->side === 'debit' ? 'credit' : 'debit', $inverse->side);
        }
        $this->reject('BILLING_ALREADY_REVERSED',
            fn () => $ledger->reverse($user, (int) $original['journal_id'], 'another-reverse', 1700000001));
        $this->reject('BILLING_REVERSAL_INVALID',
            fn () => $ledger->reverse($user, (int) $reversed['journal_id'], 'reverse-reversal', 1700000001));
        $this->assertSame(2, DB::table('tx_money_journal')->count());
    }

    public function test_invalid_ownership_unbalanced_currency_and_insufficient_funds_leave_no_evidence(): void
    {
        $user = $this->account();
        [, $other] = app(NativeIdentityService::class)->create('other-money@example.test', 'runtime-password');
        $ledger = app(NativeMoneyLedger::class);
        $request = $this->credit($user, '10');
        $request['postings'][1]['owner_id'] = $other->id;
        $this->reject('BILLING_ACCOUNT_OWNER_MISMATCH', fn () => $ledger->post($user, $request));
        $request = $this->credit($user, '10');
        $request['postings'][1]['amount_minor'] = '11';
        $this->reject('BILLING_JOURNAL_UNBALANCED', fn () => $ledger->post($user, $request));
        $request = $this->credit($user, '10');
        $request['currency'] = 'usd';
        $this->reject('BILLING_CURRENCY_INVALID', fn () => $ledger->post($user, $request));
        $request = $this->credit($user, '10');
        $request['postings'][0]['side'] = 'credit';
        $request['postings'][1]['side'] = 'debit';
        $this->reject('BILLING_INSUFFICIENT_FUNDS', fn () => $ledger->post($user, $request));
        foreach (['tx_money_account', 'tx_money_journal', 'tx_money_posting'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_exact_signed_bigint_limit_and_projection_overflow_rollback(): void
    {
        $user = $this->account();
        $ledger = app(NativeMoneyLedger::class);
        $ledger->post($user, $this->credit($user, MoneyValue::MAX));
        $this->assertSame(MoneyValue::MAX, $this->balance($user));
        $request = $this->credit($user, '1');
        $request['idempotency_key'] = 'overflow';
        $this->reject('BILLING_AMOUNT_INVALID', fn () => $ledger->post($user, $request));
        $this->assertSame(MoneyValue::MAX, $this->balance($user));
        $this->assertSame(1, DB::table('tx_money_journal')->count());
    }

    public function test_reversing_spent_money_cannot_overdraw_a_wallet_or_leave_partial_evidence(): void
    {
        $user = $this->account();
        $ledger = app(NativeMoneyLedger::class);
        $credit = $ledger->post($user, $this->credit($user, '100'));
        $capture = $this->credit($user, '100');
        $capture['business_type'] = 'wallet_capture';
        $capture['postings'] = [
            ['owner_id' => $user, 'purpose' => 'wallet_available', 'side' => 'debit', 'amount_minor' => '100'],
            ['owner_id' => 0, 'purpose' => 'sales_revenue', 'side' => 'credit', 'amount_minor' => '100'],
        ];
        $ledger->post($user, $capture);
        $this->reject('BILLING_INSUFFICIENT_FUNDS',
            fn () => $ledger->reverse($user, (int) $credit['journal_id'], 'spent-credit-reverse', 1700000001));
        $this->assertSame('0', $this->balance($user));
        $this->assertSame('100', $this->balance(0, 'provider_clearing'));
        $this->assertSame('100', $this->balance(0, 'sales_revenue'));
        $this->assertSame(2, DB::table('tx_money_journal')->count());
        $this->assertSame(4, DB::table('tx_money_posting')->count());
        $this->assertFalse(DB::table('tx_money_journal')->whereNotNull('reverses_journal_id')->exists());
    }

    public function test_posting_write_failure_rolls_back_accounts_journal_and_projection_together(): void
    {
        $user = $this->account();
        $fired = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$fired): void {
            if (!$fired && str_contains(strtolower($sql), 'insert into')
                && str_contains($sql, 'tx_money_posting')) {
                $fired = true;
                throw new RuntimeException('injected-posting-failure');
            }
        });
        try {
            app(NativeMoneyLedger::class)->post($user, $this->credit($user, '100'));
            $this->fail('Expected posting fault.');
        } catch (RuntimeException $error) {
            $this->assertSame('injected-posting-failure', $error->getMessage());
        }
        $this->assertTrue($fired);
        foreach (['tx_money_account', 'tx_money_journal', 'tx_money_posting'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_replay_checks_original_posting_and_account_evidence(): void
    {
        $user = $this->account();
        $ledger = app(NativeMoneyLedger::class);
        $request = $this->credit($user, '100');
        $ledger->post($user, $request);
        DB::table('tx_money_posting')->update(['amount_minor' => '99']);
        $this->reject('BILLING_EVIDENCE_CORRUPT', fn () => $ledger->post($user, $request));
        DB::table('tx_money_posting')->update(['amount_minor' => '100']);
        DB::table('tx_money_account')->where('owner_id', $user)->update(['normal_side' => 'debit']);
        $this->reject('BILLING_ACCOUNT_CORRUPT', fn () => $ledger->post($user, $request));
        $this->assertSame('100', $this->balance($user));
    }

    public function test_public_mutations_refuse_outer_transaction_and_financial_user_delete_is_restricted(): void
    {
        $user = $this->account();
        DB::transaction(function () use ($user): void {
            $this->reject('BILLING_OUTER_TRANSACTION_UNSUPPORTED',
                fn () => app(NativeMoneyLedger::class)->post($user, $this->credit($user, '1')));
        });
        app(NativeMoneyLedger::class)->post($user, $this->credit($user, '1'));
        try {
            DB::table('tx_user')->where('id', $user)->delete();
            $this->fail('Financial owner must not be deleted.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('tx_money_journal')->count());
        }
        $migration = require database_path('migrations/2026_10_16_000004_create_native_money_ledger.php');
        try {
            $migration->down();
            $this->fail('Populated financial tables must not be rolled back.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Native money evidence exists', $error->getMessage());
        }
    }

    private function account(): int
    {
        $this->activate();
        [, $user] = app(NativeIdentityService::class)->create('money@example.test', 'runtime-password');
        return (int) $user->id;
    }

    private function credit(int $user, string $amount): array
    {
        return ['business_type' => 'wallet_credit', 'business_ref' => 'internal-evidence',
            'idempotency_key' => 'credit', 'currency' => 'USD', 'occurred_at' => 1700000000,
            'postings' => [
                ['owner_id' => '0', 'purpose' => 'provider_clearing', 'side' => 'debit', 'amount_minor' => $amount],
                ['owner_id' => $user, 'purpose' => 'wallet_available', 'side' => 'credit', 'amount_minor' => $amount],
            ]];
    }

    private function balance(int $owner, string $purpose = 'wallet_available'): string
    {
        return (string) DB::table('tx_money_account')->where('owner_id', $owner)->where('purpose', $purpose)->value('balance_minor');
    }

    private function reject(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected '.$code);
        } catch (BillingError $error) {
            $this->assertSame($code, $error->errorCode);
        }
    }
}
