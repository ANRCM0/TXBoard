<?php

namespace App\Domains\Billing;

use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Subscription\Services\SubscriptionTermsTimeline;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Administrative credits/debits have their own contra, evidence and permission. */
final class NativeAccountAdjustments
{
    private const PURPOSES = ['wallet_available', 'wallet_reserved', 'commission_available', 'commission_reserved'];
    private const POSTING_LIMIT = 10000;

    public function __construct(private NativeMoneyLedger $ledger) {}

    public function balances(int $actorId, int $userId, string $currency): array
    {
        $currency = MoneyValue::currency($currency);
        return $this->owned($actorId, $userId, false, function (array $installation, User $target) use ($actorId, $userId, $currency): array {
            return $this->snapshot($installation, $userId, $currency) + ['editable' => !$target->is_admin && !$target->is_staff && $actorId !== $userId];
        });
    }

    public function create(int $actorId, int $userId, array $input, string $key): array
    {
        $request = $this->normalize($actorId, $userId, $input, $key);
        return $this->owned($actorId, $userId, true, function (array $installation, User $target) use ($actorId, $userId, $request): array {
            if ($target->is_admin || $target->is_staff || $actorId === $userId) throw new BillingError('BILLING_ADJUSTMENT_TARGET_PROTECTED');
            $request['installation_id'] = $installation['installation_id'];
            $old = DB::table('tx_native_account_adjustment')->where('installation_id', $installation['installation_id'])
                ->where('actor_id', $actorId)->where('request_key', $request['request_key'])->lockForUpdate()->first();
            if ($old && !hash_equals((string) $old->request_hash, $this->hash($request))) throw new BillingError('BILLING_ADJUSTMENT_IDEMPOTENCY_CONFLICT');
            $identities = [['owner_id' => '0', 'purpose' => 'admin_adjustment_control']];
            foreach (self::PURPOSES as $purpose) $identities[] = ['owner_id' => (string) $userId, 'purpose' => $purpose];
            $this->ledger->lockAccountsLocked($installation, $userId, $request['currency'], $identities);
            $before = $this->snapshot($installation, $userId, $request['currency']);
            if ($old) return $this->inspectRow($old)[1];
            if (!hash_equals($before['ledger_revision'], $request['expected_ledger_revision'])) throw new BillingError('BILLING_ADJUSTMENT_VERSION_CONFLICT');
            $bucket = $request['bucket']; $balance = $before[$bucket.'_minor'];
            if ($request['direction'] === 'debit' && bccomp($balance, $request['amount_minor'], 0) < 0) throw new BillingError('BILLING_INSUFFICIENT_FUNDS');
            $now = time(); $journalRequest = $this->journalRequest($request, $now);
            $journal = $this->ledger->postLocked($installation, $userId, $journalRequest);
            $after = $this->snapshot($installation, $userId, $request['currency'], $journal['journal_id']);
            $id = DB::table('tx_native_account_adjustment')->insertGetId(['installation_id' => $installation['installation_id'],
                'actor_id' => $actorId, 'user_id' => $userId, 'request_key' => $request['request_key'], 'currency' => $request['currency'],
                'bucket' => $bucket, 'direction' => $request['direction'], 'amount_minor' => $request['amount_minor'], 'journal_id' => $journal['journal_id'],
                'request_hash' => $this->hash($request), 'request_snapshot' => SubscriptionTermsTimeline::json($request),
                'result_snapshot' => '{}', 'content_hash' => str_repeat('0', 64), 'created_at' => $now]);
            $result = ['adjustment_id' => (string) $id, 'journal_id' => $journal['journal_id'], 'user_id' => (string) $userId,
                'currency' => $request['currency'], 'bucket' => $bucket, 'direction' => $request['direction'], 'amount_minor' => $request['amount_minor'],
                'before_minor' => $balance, 'after_minor' => $after[$bucket.'_minor'], 'ledger_revision' => $after['ledger_revision'],
                'reason' => $request['reason'], 'note' => $request['note'], 'actor_id' => (string) $actorId, 'occurred_at' => (string) $now];
            $result = $this->decode(SubscriptionTermsTimeline::json($result));
            DB::table('tx_native_account_adjustment')->where('id', $id)->update(['result_snapshot' => SubscriptionTermsTimeline::json($result),
                'content_hash' => $this->hash(['request' => $request, 'result' => $result])]);
            $this->inspectRow(DB::table('tx_native_account_adjustment')->where('id', $id)->first());
            return $result;
        });
    }

    public function history(int $actorId, int $userId, string $currency, ?string $before = null, int $limit = 25): array
    {
        $currency = MoneyValue::currency($currency);
        if ($before !== null) MoneyValue::minor($before, true);
        if ($limit < 1 || $limit > 100) throw new BillingError('BILLING_ADJUSTMENT_INPUT_INVALID');
        return $this->owned($actorId, $userId, false, function (array $installation) use ($userId, $currency, $before, $limit): array {
            $query = DB::table('tx_native_account_adjustment')->where('installation_id', $installation['installation_id'])->where('user_id', $userId)->where('currency', $currency);
            if ($before !== null) $query->where('id', '<', $before);
            $rows = $query->orderByDesc('id')->limit($limit + 1)->sharedLock()->get(); $items = [];
            foreach ($rows->take($limit) as $row) $items[] = $this->inspectRow($row)[1];
            return ['items' => $items, 'next_cursor' => $rows->count() > $limit ? (string) $rows[$limit - 1]->id : null];
        });
    }

    /** Reconciliation reuses the business evidence verifier without creating or repairing accounts. */
    public function inspectJournal(int $journalId): array
    {
        if (!Schema::hasTable('tx_native_account_adjustment')) throw new BillingError('BILLING_ADJUSTMENT_CAPABILITY_UNAVAILABLE');
        $row = DB::table('tx_native_account_adjustment')->where('journal_id', $journalId)->first();
        if (!$row) throw new BillingError('BILLING_ADJUSTMENT_EVIDENCE_INVALID');
        return $this->inspectRow($row)[1];
    }

    private function snapshot(array $installation, int $userId, string $currency, ?string $pendingJournal = null): array
    {
        $accounts = DB::table('tx_money_account')->where('installation_id', $installation['installation_id'])->where('owner_id', $userId)
            ->where('currency', $currency)->orderBy('purpose')->limit(5)->sharedLock()->get();
        if ($accounts->count() > 4) throw new BillingError('BILLING_ACCOUNT_CORRUPT');
        $balances = array_fill_keys(self::PURPOSES, '0'); $evidence = []; $scanned = 0; $seen = [];
        foreach ($accounts as $account) {
            if (!in_array($account->purpose, self::PURPOSES, true) || (string) $account->user_id !== (string) $userId || $account->normal_side !== 'credit') throw new BillingError('BILLING_ACCOUNT_CORRUPT');
            $rows = DB::table('tx_money_posting as p')->join('tx_money_journal as j', 'j.id', '=', 'p.journal_id')->where('p.account_id', $account->id)
                ->orderBy('p.id')->limit(self::POSTING_LIMIT - $scanned + 1)->sharedLock()->get(['p.id', 'p.journal_id', 'p.side', 'p.amount_minor', 'j.request_hash']);
            $scanned += $rows->count();
            if ($scanned > self::POSTING_LIMIT) throw new BillingError('BILLING_ADJUSTMENT_HISTORY_LIMIT');
            $sum = '0';
            foreach ($rows as $row) {
                if (!isset($seen[$row->journal_id])) {
                    $journal = $this->ledger->inspectJournalLocked((int) $row->journal_id);
                    if ($journal['installation_id'] !== $installation['installation_id'] || $journal['user_id'] !== (string) $userId || $journal['currency'] !== $currency) throw new BillingError('BILLING_EVIDENCE_CORRUPT');
                    if ($journal['business_type'] === 'admin_adjustment' && (string) $row->journal_id !== $pendingJournal) $this->inspectJournal((int) $row->journal_id);
                    $seen[$row->journal_id] = true;
                }
                $amount = MoneyValue::minor((string) $row->amount_minor, true);
                $sum = MoneyValue::add($sum, $row->side === 'credit' ? $amount : '-'.$amount);
                $evidence[$account->purpose][] = [(string) $row->id, (string) $row->journal_id, $row->request_hash, $row->side, $amount];
            }
            $balances[$account->purpose] = MoneyValue::minor((string) $account->balance_minor);
            if ($sum !== $balances[$account->purpose]) throw new BillingError('BILLING_ACCOUNT_PROJECTION_MISMATCH');
        }
        $result = ['user_id' => (string) $userId, 'currency' => $currency];
        foreach ($balances as $purpose => $balance) $result[$purpose.'_minor'] = $balance;
        $result['ledger_revision'] = $this->hash(['installation_id' => $installation['installation_id'], 'balances' => $result, 'evidence' => $evidence]);
        return $result;
    }

    private function inspectRow(object $row): array
    {
        $request = $this->decode($row->request_snapshot); $result = $this->decode($row->result_snapshot);
        if (!hash_equals((string) $row->request_hash, $this->hash($request)) || !hash_equals((string) $row->content_hash, $this->hash(['request' => $request, 'result' => $result]))
            || ($request['installation_id'] ?? null) !== $row->installation_id || ($request['actor_id'] ?? null) !== (string) $row->actor_id
            || ($request['user_id'] ?? null) !== (string) $row->user_id || ($request['request_key'] ?? null) !== $row->request_key
            || ($result['adjustment_id'] ?? null) !== (string) $row->id || ($result['journal_id'] ?? null) !== (string) $row->journal_id
            || ($result['occurred_at'] ?? null) !== (string) $row->created_at) throw new BillingError('BILLING_ADJUSTMENT_EVIDENCE_INVALID');
        foreach (['currency', 'bucket', 'direction', 'amount_minor'] as $field) if (($request[$field] ?? null) !== (string) $row->$field || ($result[$field] ?? null) !== (string) $row->$field) throw new BillingError('BILLING_ADJUSTMENT_EVIDENCE_INVALID');
        foreach (['actor_id', 'user_id', 'reason', 'note'] as $field) if (($request[$field] ?? null) !== ($result[$field] ?? null)) throw new BillingError('BILLING_ADJUSTMENT_EVIDENCE_INVALID');
        $before = MoneyValue::minor($result['before_minor'] ?? null); $after = MoneyValue::minor($result['after_minor'] ?? null);
        if (MoneyValue::add($before, $request['direction'] === 'credit' ? $request['amount_minor'] : '-'.$request['amount_minor']) !== $after) throw new BillingError('BILLING_ADJUSTMENT_EVIDENCE_INVALID');
        $journal = $this->ledger->inspectJournal((int) $row->journal_id);
        foreach ($this->journalRequest($request, (int) $row->created_at) as $field => $value) if ($journal[$field] !== $value) throw new BillingError('BILLING_ADJUSTMENT_EVIDENCE_INVALID');
        if ($journal['installation_id'] !== $row->installation_id || $journal['user_id'] !== (string) $row->user_id
            || $journal['reverses_journal_id'] !== null || DB::table('tx_money_journal')->where('reverses_journal_id', $row->journal_id)->exists()) throw new BillingError('BILLING_ADJUSTMENT_EVIDENCE_INVALID');
        return [$request, $result];
    }

    private function journalRequest(array $request, int $now): array
    {
        return ['business_type' => 'admin_adjustment', 'business_ref' => 'admin-adjustment:'.$request['actor_id'].':'.$request['request_key'],
            'idempotency_key' => $request['request_key'], 'currency' => $request['currency'], 'occurred_at' => (string) $now,
            'postings' => [['owner_id' => '0', 'purpose' => 'admin_adjustment_control', 'side' => $request['direction'] === 'credit' ? 'debit' : 'credit', 'amount_minor' => $request['amount_minor']],
                ['owner_id' => $request['user_id'], 'purpose' => $request['bucket'], 'side' => $request['direction'], 'amount_minor' => $request['amount_minor']]]];
    }

    private function normalize(int $actorId, int $userId, array $input, string $key): array
    {
        $fields = ['currency', 'bucket', 'direction', 'amount_minor', 'expected_ledger_revision', 'reason', 'note'];
        if (array_diff(array_keys($input), $fields) || array_diff(array_slice($fields, 0, 6), array_keys($input))
            || !in_array($input['bucket'], ['wallet_available', 'commission_available'], true) || !in_array($input['direction'], ['credit', 'debit'], true)
            || !is_string($input['amount_minor']) || !is_string($input['expected_ledger_revision']) || !preg_match('/\A[0-9a-f]{64}\z/D', $input['expected_ledger_revision'])
            || !is_string($input['reason']) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}\z/D', $input['reason']) || !Str::isUuid($key)) throw new BillingError('BILLING_ADJUSTMENT_INPUT_INVALID');
        $note = $input['note'] ?? null;
        if ($note !== null && (!is_string($note) || mb_strlen($note) > 500 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $note))) throw new BillingError('BILLING_ADJUSTMENT_INPUT_INVALID');
        $currency = MoneyValue::currency($input['currency']);
        if ($input['bucket'] === 'commission_available' && $currency !== 'CNY') throw new BillingError('BILLING_ADJUSTMENT_COMMISSION_CURRENCY_UNSUPPORTED');
        return ['actor_id' => MoneyValue::minor($actorId, true), 'user_id' => MoneyValue::minor($userId, true), 'request_key' => strtolower($key),
            'currency' => $currency, 'bucket' => $input['bucket'], 'direction' => $input['direction'], 'amount_minor' => MoneyValue::minor($input['amount_minor'], true),
            'expected_ledger_revision' => $input['expected_ledger_revision'], 'reason' => $input['reason'], 'note' => $note === null || trim($note) === '' ? null : trim($note)];
    }

    private function owned(int $actorId, int $userId, bool $write, \Closure $operation): mixed
    {
        if ($actorId < 1 || $userId < 1 || DB::transactionLevel() !== 0) throw new BillingError('BILLING_ADJUSTMENT_INPUT_INVALID');
        return DB::transaction(function () use ($actorId, $userId, $write, $operation): mixed {
            $installation = $write ? app(InstallationAuthority::class)->assertNativeMutation() : app(InstallationAuthority::class)->assertNativeRead();
            $users = User::query()->whereIn('id', [$actorId, $userId])->orderBy('id')->lockForUpdate()->get();
            app(CurrentSessionAuthorization::class)->assertCurrentLocked($actorId, 'billing.account-adjustments.manage');
            $target = $users->firstWhere('id', $userId); if (!$target) throw new BillingError('BILLING_ACCOUNT_MISSING');
            if (!Schema::hasTable('tx_native_account_adjustment')) throw new BillingError('BILLING_ADJUSTMENT_CAPABILITY_UNAVAILABLE');
            return $operation($installation, $target);
        }, 3);
    }

    private function hash(array $value): string { return hash_hmac('sha256', SubscriptionTermsTimeline::json($value), (string) config('app.key')); }
    private function decode(string $value): array
    {
        try { $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new BillingError('BILLING_ADJUSTMENT_EVIDENCE_INVALID'); }
        if (!is_array($decoded) || array_is_list($decoded)) throw new BillingError('BILLING_ADJUSTMENT_EVIDENCE_INVALID');
        return $decoded;
    }
}
