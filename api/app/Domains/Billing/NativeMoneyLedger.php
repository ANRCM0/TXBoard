<?php

namespace App\Domains\Billing;

use Illuminate\Support\Facades\DB;

/** Internal posting primitive. The calling business service must prove the money's source. */
final class NativeMoneyLedger
{
    private const USER_PURPOSES = ['wallet_available' => 'credit', 'wallet_reserved' => 'credit'];
    private const SYSTEM_PURPOSES = ['provider_clearing' => 'debit', 'sales_revenue' => 'credit',
        'refund_liability' => 'credit'];
    private const BUSINESS_TYPES = ['wallet_credit', 'wallet_reserve', 'wallet_release', 'wallet_capture',
        'provider_capture', 'refund', 'adjustment'];

    public function post(int $userId, array $request): array
    {
        return app(NativeBillingTransaction::class)->run($userId,
            fn (array $installation): array => $this->postLocked($installation, $userId, $request));
    }

    /** Caller owns installation admission and the user/aggregate locks, before any money locks. */
    public function postLocked(array $installation, int $userId, array $request): array
    {
        return $this->write($installation, $userId, $request, null);
    }

    /** Prelock the complete account union before a business mutation posts multiple journals. */
    public function lockAccountsLocked(array $installation, int $userId, string $currency, array $identities): void
    {
        $installationId = $this->context($installation, $userId);
        $currency = MoneyValue::currency($currency);
        $this->require(array_is_list($identities) && count($identities) >= 1 && count($identities) <= 64,
            'BILLING_ACCOUNTS_INVALID');
        $accounts = [];
        foreach ($identities as $identity) {
            $this->require(is_array($identity) && count($identity) === 2
                && array_key_exists('owner_id', $identity) && array_key_exists('purpose', $identity),
                'BILLING_ACCOUNT_INVALID');
            $owner = MoneyValue::minor($identity['owner_id']);
            $this->require($owner === '0' || $owner === (string) $userId, 'BILLING_ACCOUNT_OWNER_MISMATCH');
            $purposes = $owner === '0' ? self::SYSTEM_PURPOSES : self::USER_PURPOSES;
            $purpose = $identity['purpose'];
            $this->require(is_string($purpose) && isset($purposes[$purpose]), 'BILLING_ACCOUNT_PURPOSE_INVALID');
            $accounts[] = ['owner_id' => $owner, 'purpose' => $purpose];
        }
        $this->lockAccounts($installationId, $userId, ['currency' => $currency, 'postings' => $accounts]);
    }

    /** Read-only evidence validation; never creates accounts, repairs balances or takes money locks. */
    public function inspectJournal(int $journalId): array
    {
        $journal = DB::table('tx_money_journal')->where('id', $journalId)->first();
        $this->require($journal !== null, 'BILLING_JOURNAL_MISSING');
        return $this->result($journal) + ['postings' => $this->evidence($journal)];
    }

    public function reverse(int $userId, int $journalId, string $key, int $occurredAt): array
    {
        return app(NativeBillingTransaction::class)->run($userId,
            fn (array $installation): array => $this->reverseLocked($installation, $userId, $journalId, $key, $occurredAt));
    }

    /** Caller owns the same account-first aggregate locks required by postLocked(). */
    public function reverseLocked(array $installation, int $userId, int $journalId, string $key, int $occurredAt): array
    {
        $installationId = $this->context($installation, $userId);
        $original = DB::table('tx_money_journal')->where('id', $journalId)
            ->where('installation_id', $installationId)->where('user_id', $userId)->first();
        $this->require($original !== null && $original->business_type !== 'reversal', 'BILLING_REVERSAL_INVALID');
        // Original evidence is immutable; acquire money locks before locking journal evidence.
        $postings = $this->evidence($original);
        foreach ($postings as &$posting) {
            $posting['side'] = $posting['side'] === 'debit' ? 'credit' : 'debit';
        }
        unset($posting);
        return $this->write($installation, $userId, [
            'business_type' => 'reversal', 'business_ref' => (string) $journalId,
            'idempotency_key' => $key, 'currency' => $original->currency,
            'occurred_at' => $occurredAt, 'postings' => $postings,
        ], $journalId);
    }

    private function write(array $installation, int $userId, array $input, ?int $reverses): array
    {
        $installationId = $this->context($installation, $userId);
        $request = $this->normalize($input, $userId, $reverses);
        $requestHash = $this->requestHash($installationId, $userId, $request, $reverses);
        $accounts = $this->lockAccounts($installationId, $userId, $request);
        $identity = ['installation_id' => $installationId, 'user_id' => $userId,
            'business_type' => $request['business_type'], 'business_ref' => $request['business_ref'],
            'idempotency_key' => $request['idempotency_key']];
        $existing = DB::table('tx_money_journal')->where($identity)->lockForUpdate()->first();
        if ($existing !== null) {
            $this->require(hash_equals($existing->request_hash, $requestHash), 'BILLING_IDEMPOTENCY_CONFLICT');
            $this->require($this->storedRequest($existing) === $request
                && ($existing->reverses_journal_id === null ? null : (string) $existing->reverses_journal_id)
                    === ($reverses === null ? null : (string) $reverses), 'BILLING_EVIDENCE_CORRUPT');
            return $this->result($existing);
        }
        if ($reverses !== null) {
            $original = DB::table('tx_money_journal')->where('id', $reverses)->lockForUpdate()->first();
            $this->require($original !== null && $original->installation_id === $installationId
                && (int) $original->user_id === $userId, 'BILLING_REVERSAL_INVALID');
            $this->require(!DB::table('tx_money_journal')->where('reverses_journal_id', $reverses)
                ->lockForUpdate()->exists(), 'BILLING_ALREADY_REVERSED');
        }
        $balances = [];
        foreach ($request['postings'] as $posting) {
            $accountKey = $this->accountKey($posting);
            $account = $accounts[$accountKey];
            $amount = $posting['side'] === $account->normal_side ? $posting['amount_minor'] : '-'.$posting['amount_minor'];
            $balances[$accountKey] = MoneyValue::add($balances[$accountKey] ?? (string) $account->balance_minor, $amount);
        }
        foreach ($balances as $accountKey => $balance) {
            $account = $accounts[$accountKey];
            $this->require((int) $account->owner_id === 0 || !str_starts_with($balance, '-'), 'BILLING_INSUFFICIENT_FUNDS');
        }
        $id = DB::table('tx_money_journal')->insertGetId($identity + [
            'request_hash' => $requestHash, 'currency' => $request['currency'],
            'occurred_at' => $request['occurred_at'], 'reverses_journal_id' => $reverses, 'created_at' => time(),
        ]);
        foreach ($request['postings'] as $position => $posting) {
            DB::table('tx_money_posting')->insert([
                'journal_id' => $id, 'account_id' => $accounts[$this->accountKey($posting)]->id,
                'position' => $position, 'side' => $posting['side'], 'amount_minor' => $posting['amount_minor'],
            ]);
        }
        foreach ($balances as $accountKey => $balance) {
            DB::table('tx_money_account')->where('id', $accounts[$accountKey]->id)->update(['balance_minor' => $balance]);
        }
        return $this->result(DB::table('tx_money_journal')->where('id', $id)->first());
    }

    private function normalize(array $input, int $userId, ?int $reverses): array
    {
        $this->require(count(array_diff(array_keys($input), [
            'business_type', 'business_ref', 'idempotency_key', 'currency', 'occurred_at', 'postings',
        ])) === 0, 'BILLING_REQUEST_INVALID');
        $type = $input['business_type'] ?? null;
        $this->require(is_string($type) && ($reverses === null
            ? in_array($type, self::BUSINESS_TYPES, true) : $type === 'reversal'), 'BILLING_BUSINESS_TYPE_INVALID');
        $postings = $input['postings'] ?? null;
        $this->require(is_array($postings) && array_is_list($postings)
            && count($postings) >= 2 && count($postings) <= 64, 'BILLING_POSTINGS_INVALID');
        $debit = '0';
        $credit = '0';
        $normalized = [];
        foreach ($postings as $posting) {
            $this->require(is_array($posting) && count(array_diff(array_keys($posting),
                ['owner_id', 'purpose', 'side', 'amount_minor'])) === 0, 'BILLING_POSTING_INVALID');
            $owner = MoneyValue::minor($posting['owner_id'] ?? null);
            $this->require($owner === '0' || $owner === (string) $userId, 'BILLING_ACCOUNT_OWNER_MISMATCH');
            $purpose = $posting['purpose'] ?? null;
            $purposes = $owner === '0' ? self::SYSTEM_PURPOSES : self::USER_PURPOSES;
            $this->require(is_string($purpose) && isset($purposes[$purpose]), 'BILLING_ACCOUNT_PURPOSE_INVALID');
            $side = $posting['side'] ?? null;
            $this->require(in_array($side, ['debit', 'credit'], true), 'BILLING_POSTING_SIDE_INVALID');
            $amount = MoneyValue::minor($posting['amount_minor'] ?? null, true);
            if ($side === 'debit') { $debit = MoneyValue::add($debit, $amount); }
            else { $credit = MoneyValue::add($credit, $amount); }
            $normalized[] = ['owner_id' => $owner, 'purpose' => $purpose, 'side' => $side, 'amount_minor' => $amount];
        }
        $this->require($debit !== '0' && $debit === $credit, 'BILLING_JOURNAL_UNBALANCED');
        return ['business_type' => $type,
            'business_ref' => MoneyValue::key($input['business_ref'] ?? null),
            'idempotency_key' => MoneyValue::key($input['idempotency_key'] ?? null),
            'currency' => MoneyValue::currency($input['currency'] ?? null),
            'occurred_at' => MoneyValue::minor($input['occurred_at'] ?? null), 'postings' => $normalized];
    }

    private function lockAccounts(string $installationId, int $userId, array $request): array
    {
        $identities = [];
        foreach ($request['postings'] as $posting) { $identities[$this->accountKey($posting)] = $posting; }
        ksort($identities, SORT_STRING);
        $accounts = [];
        foreach ($identities as $key => $posting) {
            $owner = (int) $posting['owner_id'];
            $normal = ($owner === 0 ? self::SYSTEM_PURPOSES : self::USER_PURPOSES)[$posting['purpose']];
            $identity = ['installation_id' => $installationId, 'owner_id' => $owner,
                'purpose' => $posting['purpose'], 'currency' => $request['currency']];
            DB::table('tx_money_account')->insertOrIgnore($identity + [
                'user_id' => $owner === 0 ? null : $userId, 'normal_side' => $normal,
                'balance_minor' => '0', 'created_at' => time(),
            ]);
            $account = DB::table('tx_money_account')->where($identity)->lockForUpdate()->first();
            $this->require($account !== null && $account->normal_side === $normal
                && $account->installation_id === $installationId && (int) $account->owner_id === $owner
                && $account->purpose === $posting['purpose'] && $account->currency === $request['currency']
                && ($account->user_id === null ? null : (int) $account->user_id) === ($owner === 0 ? null : $userId),
                'BILLING_ACCOUNT_CORRUPT');
            MoneyValue::signed((string) $account->balance_minor);
            $this->require($owner === 0 || !str_starts_with((string) $account->balance_minor, '-'), 'BILLING_ACCOUNT_CORRUPT');
            $accounts[$key] = $account;
        }
        return $accounts;
    }

    private function accountKey(array $posting): string
    {
        return str_pad($posting['owner_id'], 19, '0', STR_PAD_LEFT).':'.$posting['purpose'];
    }

    private function storedRequest(object $journal): array
    {
        return ['business_type' => $journal->business_type, 'business_ref' => $journal->business_ref,
            'idempotency_key' => $journal->idempotency_key, 'currency' => $journal->currency,
            'occurred_at' => (string) $journal->occurred_at, 'postings' => $this->evidence($journal, false)];
    }

    private function evidence(object $journal, bool $verifyHash = true): array
    {
        $rows = DB::table('tx_money_posting as p')->join('tx_money_account as a', 'a.id', '=', 'p.account_id')
            ->where('p.journal_id', $journal->id)->orderBy('p.position')->limit(65)
            ->get(['p.position', 'p.side', 'p.amount_minor', 'a.owner_id', 'a.user_id', 'a.purpose',
                'a.currency', 'a.installation_id', 'a.normal_side']);
        $postings = [];
        foreach ($rows as $position => $row) {
            $owner = (string) $row->owner_id;
            $purposes = $owner === '0' ? self::SYSTEM_PURPOSES : self::USER_PURPOSES;
            $this->require((int) $row->position === $position && $row->installation_id === $journal->installation_id
                && $row->currency === $journal->currency && isset($purposes[$row->purpose])
                && $row->normal_side === $purposes[$row->purpose]
                && ($owner === '0' ? $row->user_id === null
                    : ($owner === (string) $journal->user_id && (string) $row->user_id === $owner)),
                'BILLING_EVIDENCE_CORRUPT');
            $postings[] = ['owner_id' => $owner, 'purpose' => $row->purpose,
                'side' => $row->side, 'amount_minor' => (string) $row->amount_minor];
        }
        $request = $this->normalize(['business_type' => $journal->business_type, 'business_ref' => $journal->business_ref,
            'idempotency_key' => $journal->idempotency_key, 'currency' => $journal->currency,
            'occurred_at' => (string) $journal->occurred_at, 'postings' => $postings], (int) $journal->user_id,
            $journal->reverses_journal_id === null ? null : (int) $journal->reverses_journal_id);
        if ($verifyHash) {
            $this->require(hash_equals($journal->request_hash, $this->requestHash(
                $journal->installation_id, (int) $journal->user_id, $request,
                $journal->reverses_journal_id === null ? null : (int) $journal->reverses_journal_id,
            )), 'BILLING_EVIDENCE_CORRUPT');
        }
        return $postings;
    }

    private function context(array $installation, int $userId): string
    {
        $this->require(DB::transactionLevel() > 0 && $userId > 0
            && is_string($installation['installation_id'] ?? null)
            && preg_match('/\A[0-9a-f-]{36}\z/D', $installation['installation_id']) === 1,
            'BILLING_TRANSACTION_REQUIRED');
        return $installation['installation_id'];
    }

    private function requestHash(string $installationId, int $userId, array $request, ?int $reverses): string
    {
        return MoneyValue::hash($request + ['installation_id' => $installationId, 'user_id' => (string) $userId,
            'reverses_journal_id' => $reverses === null ? null : (string) $reverses]);
    }

    private function result(object $journal): array
    {
        return ['journal_id' => (string) $journal->id, 'installation_id' => $journal->installation_id,
            'user_id' => (string) $journal->user_id, 'business_type' => $journal->business_type,
            'business_ref' => $journal->business_ref, 'idempotency_key' => $journal->idempotency_key,
            'currency' => $journal->currency, 'occurred_at' => (string) $journal->occurred_at,
            'request_hash' => $journal->request_hash,
            'reverses_journal_id' => $journal->reverses_journal_id === null ? null : (string) $journal->reverses_journal_id];
    }

    private function require(bool $condition, string $error): void
    {
        if (!$condition) { throw new BillingError($error); }
    }
}
