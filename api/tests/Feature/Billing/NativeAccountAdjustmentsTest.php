<?php

namespace Tests\Feature\Billing;

use App\Domains\Billing\NativeAccountAdjustments;
use App\Domains\Billing\NativeBillingReconciliation;
use App\Domains\Billing\NativeMoneyLedger;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeFinancialWorkflows;
use Tests\Support\NativeRuntimeTestCase;

final class NativeAccountAdjustmentsTest extends NativeRuntimeTestCase
{
    use NativeFinancialWorkflows;

    public function test_exact_wallet_adjustments_balance_a_dedicated_contra_and_replay_without_legacy_or_revenue_changes(): void
    {
        [$admin, $user, $owner] = $this->opened();
        $legacy = $user->fresh()->getAttributes(); $before = $owner->balances($admin->id, $user->id, 'CNY');
        $key = $this->workflowKey(); $input = $this->input($before, '9007199254740993');
        $result = $owner->create($admin->id, $user->id, $input, $key);
        $this->assertSame('9007199254740993', $result['after_minor']); $this->assertSame('0', $result['before_minor']);
        $this->assertSame($result, $owner->create($admin->id, $user->id, $input, $key));
        $this->assertSame(1, DB::table('tx_native_account_adjustment')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count()); $this->assertSame(2, DB::table('tx_money_posting')->count());
        $journal = app(NativeMoneyLedger::class)->inspectJournal((int) $result['journal_id']);
        $this->assertSame('admin_adjustment', $journal['business_type']);
        $this->assertSame('admin_adjustment_control', $journal['postings'][0]['purpose']);
        $this->assertSame('debit', $journal['postings'][0]['side']); $this->assertSame('credit', $journal['postings'][1]['side']);
        $this->assertSame($legacy, $user->fresh()->getAttributes());
        $this->assertSame(0, DB::table('tx_payment_receipt')->count()); $this->assertSame(0, DB::table('tx_native_commission_reward')->count());
        $report = app(NativeBillingReconciliation::class)->inspect($user->id);
        $this->assertSame([], $report['issues']); $this->assertTrue($report['complete']);
        $this->assertSame($result, $report['admin_adjustments'][0]);
        $this->assertSame($result, $owner->history($admin->id, $user->id, 'CNY')['items'][0]);
        $input['amount_minor'] = '1';
        $this->workflowBillingFailure('BILLING_ADJUSTMENT_IDEMPOTENCY_CONFLICT', fn () => $owner->create($admin->id, $user->id, $input, $key));
    }

    public function test_debits_preserve_reservations_and_cas_and_currency_boundaries(): void
    {
        [$admin, $user, $owner] = $this->opened();
        $first = $owner->balances($admin->id, $user->id, 'CNY'); $input = $this->input($first, '1000');
        $owner->create($admin->id, $user->id, $input, $this->workflowKey());
        $this->workflowBillingFailure('BILLING_ADJUSTMENT_VERSION_CONFLICT', fn () => $owner->create($admin->id, $user->id, $input, $this->workflowKey()));
        app(NativeMoneyLedger::class)->post($user->id, ['business_type' => 'wallet_reserve', 'business_ref' => 'adjustment-reserve', 'idempotency_key' => 'adjustment-reserve',
            'currency' => 'CNY', 'occurred_at' => (string) time(), 'postings' => [
                ['owner_id' => (string) $user->id, 'purpose' => 'wallet_available', 'side' => 'debit', 'amount_minor' => '800'],
                ['owner_id' => (string) $user->id, 'purpose' => 'wallet_reserved', 'side' => 'credit', 'amount_minor' => '800']]]);
        $snapshot = $owner->balances($admin->id, $user->id, 'CNY');
        $debit = $this->input($snapshot, '201', 'debit');
        $this->workflowBillingFailure('BILLING_INSUFFICIENT_FUNDS', fn () => $owner->create($admin->id, $user->id, $debit, $this->workflowKey()));
        $debit['amount_minor'] = '200'; $owner->create($admin->id, $user->id, $debit, $this->workflowKey());
        $after = $owner->balances($admin->id, $user->id, 'CNY');
        $this->assertSame('0', $after['wallet_available_minor']); $this->assertSame('800', $after['wallet_reserved_minor']);
        $this->assertSame('0', $owner->balances($admin->id, $user->id, 'USD')['wallet_available_minor']);
        $usd = $this->input($owner->balances($admin->id, $user->id, 'USD'), '1'); $usd['bucket'] = 'commission_available';
        $this->workflowBillingFailure('BILLING_ADJUSTMENT_COMMISSION_CURRENCY_UNSUPPORTED', fn () => $owner->create($admin->id, $user->id, $usd, $this->workflowKey()));
    }

    public function test_commission_adjustment_is_available_funds_without_fabricating_earned_rewards_and_auth_is_current(): void
    {
        [$admin, $user, $owner] = $this->opened(); $input = $this->input($owner->balances($admin->id, $user->id, 'CNY'), '1234');
        $input['bucket'] = 'commission_available'; $key = $this->workflowKey();
        $owner->create($admin->id, $user->id, $input, $key);
        $this->assertSame('1234', $owner->balances($admin->id, $user->id, 'CNY')['commission_available_minor']);
        $this->assertSame(0, DB::table('tx_native_commission_reward')->count());
        DB::table('tx_internal_actor_grant')->where('user_id', $admin->id)->delete();
        $this->workflowHttpFailure(403, fn () => $owner->create($admin->id, $user->id, $input, $key));
        $this->workflowHttpFailure(403, fn () => $owner->balances($admin->id, $user->id, 'CNY'));
        DB::table('tx_internal_actor_grant')->insert(['user_id' => $admin->id, 'permission' => 'billing.account-adjustments.manage', 'created_at' => time()]);
        $admin->tokens()->delete(); $this->expectException(\Illuminate\Auth\AuthenticationException::class);
        $owner->create($admin->id, $user->id, $input, $key);
    }

    public function test_corrupted_balance_or_business_evidence_is_refused_and_reconciliation_reports_it(): void
    {
        [$admin, $user, $owner] = $this->opened();
        $owner->create($admin->id, $user->id, $this->input($owner->balances($admin->id, $user->id, 'CNY'), '100'), $this->workflowKey());
        DB::table('tx_money_account')->where('owner_id', $user->id)->where('purpose', 'wallet_available')->update(['balance_minor' => '101']);
        $this->workflowBillingFailure('BILLING_ACCOUNT_PROJECTION_MISMATCH', fn () => $owner->balances($admin->id, $user->id, 'CNY'));
        DB::table('tx_money_account')->where('owner_id', $user->id)->where('purpose', 'wallet_available')->update(['balance_minor' => '100']);
        DB::table('tx_native_account_adjustment')->update(['content_hash' => str_repeat('0', 64)]);
        $this->workflowBillingFailure('BILLING_ADJUSTMENT_EVIDENCE_INVALID', fn () => $owner->balances($admin->id, $user->id, 'CNY'));
        $this->assertNotEmpty(app(NativeBillingReconciliation::class)->inspect($user->id)['issues']);
    }

    public function test_protected_targets_and_cross_target_key_reuse_cannot_move_funds(): void
    {
        [$admin, $user, $owner] = $this->opened();
        $other = $this->workflowActor(); $staff = $this->workflowActor(); $staff->is_staff = true; $staff->save();
        foreach ([$admin, $staff] as $target) {
            $balances = $owner->balances($admin->id, $target->id, 'CNY');
            $this->assertFalse($balances['editable']);
            $this->workflowBillingFailure('BILLING_ADJUSTMENT_TARGET_PROTECTED', fn () => $owner->create(
                $admin->id, $target->id, $this->input($balances, '1'), $this->workflowKey()));
        }
        $this->assertSame(0, DB::table('tx_money_journal')->count());
        $key = $this->workflowKey();
        $owner->create($admin->id, $user->id, $this->input($owner->balances($admin->id, $user->id, 'CNY'), '100'), $key);
        $this->workflowBillingFailure('BILLING_ADJUSTMENT_IDEMPOTENCY_CONFLICT', fn () => $owner->create(
            $admin->id, $other->id, $this->input($owner->balances($admin->id, $other->id, 'CNY'), '100'), $key));
        $this->assertSame('0', $owner->balances($admin->id, $other->id, 'CNY')['wallet_available_minor']);
        $this->assertSame(1, DB::table('tx_native_account_adjustment')->count());
        $this->assertSame(1, DB::table('tx_money_journal')->count());
    }

    private function opened(): array
    {
        $this->activate(); $admin = $this->workflowActor(true, ['billing.account-adjustments.manage']); $this->authenticateWorkflowActor($admin);
        return [$admin, $this->workflowActor(), app(NativeAccountAdjustments::class)];
    }

    private function input(array $balances, string $amount, string $direction = 'credit'): array
    {
        return ['currency' => $balances['currency'], 'bucket' => 'wallet_available', 'direction' => $direction, 'amount_minor' => $amount,
            'expected_ledger_revision' => $balances['ledger_revision'], 'reason' => 'support_case', 'note' => 'Service correction'];
    }
}
