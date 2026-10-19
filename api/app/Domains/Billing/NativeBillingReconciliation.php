<?php

namespace App\Domains\Billing;

use App\Domains\Installation\InstallationAuthority;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Bounded observation only. No repair, account creation, provider requests or settlement calls. */
final class NativeBillingReconciliation implements \App\Domains\Billing\Contracts\BillingReconciliation
{
    private const POSTING_SCAN_LIMIT = 2000;

    public function __construct(private readonly NativeMoneyLedger $ledger) {}

    public function inspect(int $userId, int $limit = 50, int $afterId = 0): array
    {
        if ($userId < 1 || $limit < 1 || $limit > 100 || $afterId < 0) {
            throw new BillingError('BILLING_RECONCILIATION_INPUT_INVALID');
        }
        if (DB::transactionLevel() !== 0) {
            throw new BillingError('BILLING_OUTER_TRANSACTION_UNSUPPORTED');
        }
        // One read view must cover account projections and their postings, even on
        // installations whose default connection isolation is READ COMMITTED.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        return DB::transaction(function () use ($userId, $limit, $afterId): array {
            $installation = app(InstallationAuthority::class)->assertNativeRead();
            if (!DB::table('tx_user')->where('id', $userId)->exists()) {
                throw new BillingError('BILLING_ACCOUNT_MISSING');
            }
            $installationId = $installation['installation_id'];
            $issues = [];
            $incomplete = [];
            $next = [];
            $checked = [];
            $journals = $this->page('tx_money_journal', $installationId, $userId, $limit, $afterId, $next);
            foreach ($journals as $journal) {
                $this->observe($issues, 'journal', $journal->id, fn () => $this->ledger->inspectJournal((int) $journal->id));
            }
            $checked['journals'] = $journals->count();
            $accountReconciliation = [];
            foreach (['wallet' => $userId, 'system' => 0] as $scope => $ownerId) {
                $accountReconciliation[$scope] = $this->accounts(
                    $installationId, $ownerId, $limit, $afterId, $issues, $incomplete, $next);
            }
            $checked['accounts'] = $accountReconciliation['wallet']['accounts_checked'] + $accountReconciliation['system']['accounts_checked'];
            $checked['account_postings'] = $accountReconciliation['wallet']['postings_scanned'] + $accountReconciliation['system']['postings_scanned'];
            $attempts = $this->page('tx_payment_attempt', $installationId, $userId, $limit, $afterId, $next);
            $unresolved = [];
            foreach ($attempts as $attempt) {
                $this->observe($issues, 'attempt', $attempt->id, function () use ($attempt): void {
                    $payload = $this->fields($attempt, ['installation_id', 'user_id', 'intent_type', 'recharge_id',
                        'order_id', 'purchase_terms_id', 'payment_id', 'provider', 'provider_account_ref',
                        'order_trade_no', 'amount_minor', 'currency', 'purchase_content_hash', 'request_key']);
                    $this->check(hash_equals(MoneyValue::hash($payload), $attempt->request_hash)
                        && hash_equals(hash('sha256', $attempt->request_key), $attempt->key_hash)
                        && hash_equals(MoneyValue::hash($payload + ['request_hash' => $attempt->request_hash,
                            'created_at' => (string) $attempt->created_at]), $attempt->attempt_hash),
                        'PAYMENT_ATTEMPT_EVIDENCE_INVALID');
                    $receipt = DB::table('tx_payment_receipt')->where('attempt_id', $attempt->id)->first();
                    $this->check(in_array($attempt->status, ['prepared', 'pending', 'unknown', 'failed', 'succeeded'], true)
                        && ($attempt->status === 'succeeded') === ($receipt !== null), 'PAYMENT_ATTEMPT_EVIDENCE_INVALID');
                });
                if (in_array($attempt->status, ['prepared', 'pending', 'unknown'], true)) {
                    $unresolved[] = ['attempt_id' => (string) $attempt->id, 'status' => $attempt->status];
                }
            }
            $checked['attempts'] = $attempts->count();
            $receipts = $this->page('tx_payment_receipt', $installationId, $userId, $limit, $afterId, $next);
            $liabilities = [];
            foreach ($receipts as $receipt) {
                $this->observe($issues, 'receipt', $receipt->id, function () use ($receipt, &$liabilities, &$incomplete): void {
                    $payload = $this->fields($receipt, ['installation_id', 'user_id', 'intent_type', 'recharge_id', 'order_id',
                        'payment_id', 'attempt_id', 'provider', 'provider_account_ref', 'transaction_ref', 'identity_hash',
                        'amount_minor', 'currency', 'occurred_at']);
                    $this->check(hash_equals(MoneyValue::hash($payload), $receipt->content_hash)
                        && hash_equals(MoneyValue::hash([
                            'provider' => $receipt->provider, 'provider_account_ref' => $receipt->provider_account_ref,
                            'transaction_ref' => $receipt->transaction_ref,
                        ]), $receipt->identity_hash), 'PAYMENT_RECEIPT_EVIDENCE_INVALID');
                    $attempt = DB::table('tx_payment_attempt')->where('id', $receipt->attempt_id)->first();
                    $this->check($attempt !== null && $attempt->status === 'succeeded', 'PAYMENT_RECEIPT_EVIDENCE_INVALID');
                    foreach (['installation_id', 'user_id', 'intent_type', 'recharge_id', 'order_id', 'payment_id',
                        'provider', 'provider_account_ref', 'amount_minor', 'currency'] as $field) {
                        $this->check($payload[$field] === ($attempt->$field === null ? null : (string) $attempt->$field),
                            'PAYMENT_RECEIPT_EVIDENCE_INVALID');
                    }
                    $journal = DB::table('tx_money_journal')->where('installation_id', $receipt->installation_id)
                        ->where('user_id', $receipt->user_id)->where('business_ref', (string) $receipt->attempt_id)
                        ->whereIn('business_type', ['provider_capture', 'wallet_credit'])
                        ->where('idempotency_key', $receipt->identity_hash)->first();
                    $this->check($journal !== null, 'PAYMENT_RECEIPT_UNSETTLED');
                    $evidence = $this->ledger->inspectJournal((int) $journal->id);
                    $this->check($evidence['currency'] === $receipt->currency, 'PAYMENT_RECEIPT_EVIDENCE_INVALID');
                    if (collect($evidence['postings'])->contains(fn (array $posting): bool =>
                        $posting['owner_id'] === '0' && $posting['purpose'] === 'refund_liability' && $posting['side'] === 'credit')) {
                        $refunds = DB::table('tx_native_refund')->where('receipt_id', $receipt->id)->orderBy('id')->limit(1001)->get(['amount_minor']);
                        if ($refunds->count() > 1000) { $incomplete[] = 'liability_refund_scan_limit'; return; }
                        $remaining = (string) $receipt->amount_minor;
                        foreach ($refunds as $refund) { $remaining = MoneyValue::add($remaining, '-'.MoneyValue::minor($refund->amount_minor, true)); }
                        $this->check(!str_starts_with($remaining, '-'), 'REFUND_AMOUNT_EXCEEDS_SOURCE');
                        if ($remaining !== '0') {
                            $liabilities[] = ['receipt_id' => (string) $receipt->id, 'remaining_minor' => $remaining, 'currency' => $receipt->currency];
                        }
                    }
                });
            }
            $checked['receipts'] = $receipts->count();
            $refunds = $this->page('tx_native_refund', $installationId, $userId, $limit, $afterId, $next);
            foreach ($refunds as $refund) {
                $this->observe($issues, 'refund', $refund->id, function () use ($refund): void {
                    $payload = $this->fields($refund, ['installation_id', 'user_id', 'actor_id', 'source_type', 'source_id',
                        'receipt_id', 'order_payment_id', 'order_id', 'source_journal_id', 'journal_id', 'request_key',
                        'request_hash', 'amount_minor', 'cumulative_minor', 'currency', 'destination', 'reason',
                        'entitlement_effect', 'subscription_id', 'created_at']);
                    $this->check($refund->destination === 'wallet' && hash_equals(MoneyValue::hash($payload), $refund->content_hash),
                        'REFUND_EVIDENCE_INVALID');
                    $journal = $this->ledger->inspectJournal((int) $refund->journal_id);
                    $source = $this->ledger->inspectJournal((int) $refund->source_journal_id);
                    $this->check($journal['business_type'] === 'refund' && $journal['business_ref'] === $refund->source_type.':'.$refund->source_id
                        && $journal['idempotency_key'] === $refund->request_key && $journal['user_id'] === (string) $refund->user_id
                        && $source['user_id'] === (string) $refund->user_id && $journal['currency'] === $refund->currency,
                        'REFUND_EVIDENCE_INVALID');
                    $original = $refund->source_type === 'receipt'
                        ? DB::table('tx_payment_receipt')->where('id', $refund->receipt_id)->value('amount_minor')
                        : DB::table('tx_wallet_order_reservation')->where('order_id', $refund->order_id)->value('amount_minor');
                    $this->check($original !== null && bccomp(MoneyValue::minor($refund->cumulative_minor, true),
                        MoneyValue::minor($original, true)) <= 0, 'REFUND_AMOUNT_EXCEEDS_SOURCE');
                });
            }
            $checked['refunds'] = $refunds->count();
            $work = $this->page('tx_billing_work', $installationId, $userId, $limit, $afterId, $next);
            $pendingWork = [];
            foreach ($work as $item) {
                $this->observe($issues, 'work', $item->id, function () use ($item): void {
                    $this->check(in_array($item->status, ['pending', 'completed', 'cancelled'], true)
                        && ($item->status === 'pending') === ($item->completed_at === null), 'BILLING_WORK_EVIDENCE_INVALID');
                    $this->check(DB::table('tx_order_payment')->where('order_id', $item->order_id)
                        ->where('user_id', $item->user_id)->exists(), 'BILLING_WORK_ALLOCATION_MISSING');
                });
                if ($item->status === 'pending') {
                    $pendingWork[] = ['work_id' => (string) $item->id, 'order_id' => (string) $item->order_id,
                        'attempts' => (string) $item->attempts, 'error_code' => $item->error_code];
                }
            }
            $checked['work'] = $work->count();
            if ($next !== []) { $incomplete[] = 'paged_evidence'; }
            if ($afterId !== 0) { $incomplete[] = 'partial_cursor'; }
            return [
                'installation_id' => $installationId, 'user_id' => (string) $userId,
                'scope' => 'user_financial_evidence_and_installation_system_accounts',
                'system_account_projections' => $accountReconciliation['system']['complete'] ? 'complete' : 'incomplete',
                'account_reconciliation' => $accountReconciliation,
                'read_only' => true, 'complete' => $incomplete === [], 'issues' => $issues,
                'incomplete_reasons' => array_values(array_unique($incomplete)), 'checked' => $checked,
                'next_after_id' => $next === [] ? null : (string) min($next),
                'unresolved_attempts' => $unresolved, 'refund_liabilities' => $liabilities, 'pending_work' => $pendingWork,
            ];
        });
    }

    private function accounts(string $installationId, int $ownerId, int $limit, int $afterId,
        array &$issues, array &$incomplete, array &$next): array
    {
        $scope = $ownerId === 0 ? 'system' : 'wallet';
        $accounts = DB::table('tx_money_account')->where('installation_id', $installationId)
            ->where('owner_id', $ownerId)->where('id', '>', $afterId)->orderBy('id')->limit($limit + 1)->get();
        $complete = $accounts->count() <= $limit && $afterId === 0;
        if ($accounts->count() > $limit) {
            $next[] = (int) $accounts[$limit - 1]->id;
            $incomplete[] = $scope.'_account_page';
        }
        $remaining = self::POSTING_SCAN_LIMIT;
        $reports = [];
        $checked = 0;
        foreach ($accounts->take($limit) as $account) {
            $report = [
                'account_id' => (string) $account->id, 'owner_id' => (string) $ownerId,
                'purpose' => $account->purpose, 'currency' => $account->currency,
                'projection_minor' => (string) $account->balance_minor, 'ledger_minor' => null,
                'complete' => false, 'projection_checked' => false, 'projection_matches' => null,
                'postings_scanned' => 0,
            ];
            if ($remaining === 0) {
                $incomplete[] = $scope.'_posting_scan_limit';
                $complete = false;
                $reports[] = $report;
                continue;
            }
            $rows = DB::table('tx_money_posting as p')->leftJoin('tx_money_journal as j', 'j.id', '=', 'p.journal_id')
                ->where('p.account_id', $account->id)->orderBy('p.id')->limit($remaining + 1)
                ->get(['p.side', 'p.amount_minor', 'j.installation_id', 'j.user_id', 'j.currency']);
            if ($rows->count() > $remaining) {
                $incomplete[] = $scope.'_posting_scan_limit';
                $complete = false;
                $remaining = 0;
                $reports[] = $report;
                continue;
            }
            $remaining -= $rows->count();
            $report['complete'] = true;
            $this->observe($issues, $scope.'_account', $account->id, function () use ($account, $rows, $ownerId, $installationId, &$report): void {
                $purposes = $ownerId === 0
                    ? ['provider_clearing' => 'debit', 'sales_revenue' => 'credit', 'refund_liability' => 'credit', 'gift_card_liability' => 'credit', 'commission_expense' => 'debit']
                    : ['wallet_available' => 'credit', 'wallet_reserved' => 'credit', 'commission_available' => 'credit', 'commission_reserved' => 'credit'];
                MoneyValue::currency($account->currency);
                $this->check(isset($purposes[$account->purpose]) && $account->normal_side === $purposes[$account->purpose]
                    && ($ownerId === 0 ? $account->user_id === null : (string) $account->user_id === (string) $ownerId),
                    'BILLING_ACCOUNT_CORRUPT');
                $balance = '0';
                foreach ($rows as $row) {
                    $this->check($row->installation_id === $installationId
                        && ($ownerId === 0 ? $row->user_id !== null && (int) $row->user_id > 0 : (string) $row->user_id === (string) $ownerId)
                        && $row->currency === $account->currency && in_array($row->side, ['debit', 'credit'], true),
                        'BILLING_ACCOUNT_POSTING_MISMATCH');
                    $amount = MoneyValue::minor($row->amount_minor, true);
                    $balance = MoneyValue::add($balance, $row->side === $account->normal_side ? $amount : '-'.$amount);
                    $report['postings_scanned']++;
                }
                $projection = $ownerId === 0 ? MoneyValue::signed($account->balance_minor) : MoneyValue::minor($account->balance_minor);
                $report['ledger_minor'] = $balance;
                $report['projection_checked'] = true;
                $report['projection_matches'] = $balance === $projection;
                $this->check($report['projection_matches'], 'BILLING_ACCOUNT_PROJECTION_MISMATCH');
            });
            if ($report['projection_checked']) {
                $checked++;
            } else {
                $complete = false;
                $incomplete[] = $scope.'_projection_not_checked';
            }
            $reports[] = $report;
        }
        return [
            'scope' => $ownerId === 0 ? 'installation_all_users_system_accounts' : 'selected_user_wallet_accounts',
            'complete' => $complete, 'accounts_checked' => $checked, 'postings_scanned' => array_sum(array_column($reports, 'postings_scanned')),
            'posting_scan_limit' => self::POSTING_SCAN_LIMIT, 'accounts' => $reports,
        ];
    }

    private function page(string $table, string $installationId, int $userId, int $limit, int $afterId, array &$next)
    {
        $rows = DB::table($table)->where('installation_id', $installationId)->where('user_id', $userId)
            ->where('id', '>', $afterId)->orderBy('id')->limit($limit + 1)->get();
        if ($rows->count() > $limit) { $next[] = (int) $rows[$limit - 1]->id; }
        return $rows->take($limit);
    }

    private function fields(object $row, array $fields): array
    {
        $payload = [];
        foreach ($fields as $field) { $payload[$field] = $row->$field === null ? null : (string) $row->$field; }
        return $payload;
    }

    private function observe(array &$issues, string $kind, int|string $id, \Closure $check): void
    {
        try {
            $check();
        } catch (Throwable $error) {
            $code = $error instanceof BillingError ? $error->errorCode : 'BILLING_RECONCILIATION_EVIDENCE_INVALID';
            $issues[] = ['kind' => $kind, 'id' => (string) $id, 'error_code' => $code];
        }
    }

    private function check(bool $condition, string $error): void
    {
        if (!$condition) { throw new BillingError($error); }
    }
}
