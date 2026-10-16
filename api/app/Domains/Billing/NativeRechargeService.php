<?php

namespace App\Domains\Billing;

use App\Domains\Installation\InstallationAuthority;
use Illuminate\Support\Facades\DB;

/** Native recharge intent; only the shared verified receipt authority can create wallet value. */
final class NativeRechargeService
{
    public const MIN_AMOUNT_MINOR = '100';
    public const MAX_AMOUNT_MINOR = '500000';

    public function __construct(
        private readonly NativeBillingTransaction $transactions,
        private readonly NativeMoneyLedger $ledger,
    ) {}

    public function create(int $userId, string $amountMinor, int $paymentId, string $requestKey): array
    {
        $amount = MoneyValue::minor($amountMinor, true);
        $key = MoneyValue::key($requestKey);
        $this->require(bccomp($amount, self::MIN_AMOUNT_MINOR) >= 0
            && bccomp($amount, self::MAX_AMOUNT_MINOR) <= 0 && $paymentId > 0, 'RECHARGE_INPUT_INVALID');
        return $this->transactions->run($userId, function (array $installation, object $user) use ($userId, $amount, $paymentId, $key): array {
            $existing = DB::table('tx_native_recharge')->where('installation_id', $installation['installation_id'])
                ->where('user_id', $userId)->where('key_hash', hash('sha256', $key))->lockForUpdate()->first();
            if ($existing !== null) {
                $this->verify($existing, $installation, $userId, true);
                $this->require((string) $existing->amount_minor === $amount && (int) $existing->payment_id === $paymentId
                    && $existing->request_key === $key, 'RECHARGE_IDEMPOTENCY_CONFLICT');
                return $this->dto($existing);
            }
            $this->require(!(bool) ($user->banned ?? false), 'RECHARGE_ACCOUNT_UNAVAILABLE');
            $payment = DB::table('tx_payment')->where('id', $paymentId)->lockForUpdate()->first();
            $this->require($payment !== null, 'RECHARGE_PROVIDER_UNAVAILABLE');
            $this->require(preg_match('/\A0(?:\.0+)?\z/D', (string) ($payment->handling_fee_fixed ?? '0')) === 1
                && preg_match('/\A0(?:\.0+)?\z/D', (string) ($payment->handling_fee_percent ?? '0')) === 1,
                'RECHARGE_FEES_UNSUPPORTED');
            $provider = app(NativePaymentProviderRegistry::class)->forPayment($payment, true);
            $provider->adapter->accountRef($payment);
            $this->require(in_array('CNY', $provider->adapter->capabilities()['currencies'], true),
                'RECHARGE_PROVIDER_UNAVAILABLE');
            $this->require(DB::table('tx_native_recharge')->where('installation_id', $installation['installation_id'])
                ->where('user_id', $userId)->where('status', 'pending')->orderBy('id')->limit(20)
                ->lockForUpdate()->get(['id'])->count() < 20,
                'RECHARGE_PENDING_LIMIT');
            $payload = [
                'installation_id' => $installation['installation_id'], 'user_id' => (string) $userId,
                'payment_id' => (string) $paymentId, 'trade_no' => 'WR'.strtoupper(bin2hex(random_bytes(14))),
                'request_key' => $key, 'amount_minor' => $amount, 'currency' => 'CNY', 'created_at' => (string) time(),
            ];
            $id = DB::table('tx_native_recharge')->insertGetId($payload + [
                'key_hash' => hash('sha256', $key), 'content_hash' => MoneyValue::hash($payload), 'status' => 'pending',
            ]);
            return $this->dto(DB::table('tx_native_recharge')->where('id', $id)->first());
        });
    }

    public function checkout(int $userId, string $tradeNo): array
    {
        $recharge = $this->show($userId, $tradeNo);
        return app(NativePaymentCheckout::class)->recharge($userId, (int) $recharge['recharge_id'],
            (int) $recharge['payment_method_id'], 'recharge:'.$recharge['recharge_id']);
    }

    public function show(int $userId, string $tradeNo): array
    {
        $this->require(preg_match('/\AWR[A-F0-9]{28}\z/D', $tradeNo) === 1, 'RECHARGE_MISSING');
        return DB::transaction(function () use ($userId, $tradeNo): array {
            $installation = app(InstallationAuthority::class)->assertNativeRead();
            $recharge = DB::table('tx_native_recharge')->where('installation_id', $installation['installation_id'])
                ->where('user_id', $userId)->where('trade_no', $tradeNo)->first();
            $this->require($recharge !== null, 'RECHARGE_MISSING');
            $this->verify($recharge, $installation, $userId);
            return $this->dto($recharge);
        });
    }

    public function listing(int $userId, int $limit = 20, int $afterId = 0): array
    {
        $this->require($limit >= 1 && $limit <= 50 && $afterId >= 0, 'RECHARGE_PAGE_INVALID');
        return DB::transaction(function () use ($userId, $limit, $afterId): array {
            $installation = app(InstallationAuthority::class)->assertNativeRead();
            return DB::table('tx_native_recharge')->where('installation_id', $installation['installation_id'])
                ->where('user_id', $userId)->where('id', '>', $afterId)->orderBy('id')->limit($limit)->get()
                ->map(function (object $recharge) use ($installation, $userId): array {
                    $this->verify($recharge, $installation, $userId);
                    return $this->dto($recharge);
                })->all();
        });
    }

    /** Caller holds installation admission and the owning user lock. */
    public function intentLocked(array $installation, int $userId, int $rechargeId): array
    {
        $this->context($installation, $userId);
        $row = DB::table('tx_native_recharge')->where('id', $rechargeId)->lockForUpdate()->first();
        $this->require($row !== null, 'RECHARGE_MISSING');
        $this->verify($row, $installation, $userId, true);
        return ['recharge_id' => (string) $row->id, 'payment_id' => (string) $row->payment_id, 'trade_no' => $row->trade_no,
            'amount_minor' => (string) $row->amount_minor, 'currency' => $row->currency, 'content_hash' => $row->content_hash];
    }

    /** Trusted adapter fact only; one receipt store is shared with every purchase. */
    public function settleVerifiedLocked(array $installation, int $userId, int $attemptId, array $fact): array
    {
        $this->context($installation, $userId);
        $attempt = DB::table('tx_payment_attempt')->where('id', $attemptId)->first();
        $this->require($attempt !== null && $attempt->intent_type === 'recharge'
            && (string) $attempt->user_id === (string) $userId, 'PAYMENT_ATTEMPT_MISSING');
        $intent = $this->intentLocked($installation, $userId, (int) $attempt->recharge_id);
        $row = DB::table('tx_native_recharge')->where('id', $intent['recharge_id'])->lockForUpdate()->first();
        $this->lockMoneyAccounts($installation, $userId, $intent['currency']);
        $prior = DB::table('tx_payment_receipt')->where('attempt_id', $attemptId)->lockForUpdate()->first();
        if (array_key_exists('occurred_at', $fact) && $fact['occurred_at'] === null) {
            $fact['occurred_at'] = $prior === null ? (string) time() : (string) $prior->occurred_at;
        }
        $identity = MoneyValue::hash([
            'provider' => MoneyValue::key($fact['provider'] ?? null, 32),
            'provider_account_ref' => MoneyValue::key($fact['provider_account_ref'] ?? null),
            'transaction_ref' => MoneyValue::key($fact['transaction_ref'] ?? null),
        ]);
        $isWallet = $row->receipt_id === null || ($prior !== null && (string) $row->receipt_id === (string) $prior->id);
        $journal = $this->ledger->postLocked($installation, $userId, [
            'business_type' => $isWallet ? 'wallet_credit' : 'provider_capture', 'business_ref' => (string) $attemptId,
            'idempotency_key' => $identity, 'currency' => $intent['currency'],
            'occurred_at' => MoneyValue::minor($fact['occurred_at'] ?? null, true),
            'postings' => [
                ['owner_id' => '0', 'purpose' => 'provider_clearing', 'side' => 'debit', 'amount_minor' => $intent['amount_minor']],
                ['owner_id' => $isWallet ? (string) $userId : '0',
                    'purpose' => $isWallet ? 'wallet_available' : 'refund_liability', 'side' => 'credit',
                    'amount_minor' => $intent['amount_minor']],
            ],
        ]);
        $receipt = app(NativePaymentAttempts::class)->recordVerifiedReceiptLocked($installation, $userId, $attemptId, $fact);
        $this->require(($receipt['recharge_id'] ?? null) === $intent['recharge_id']
            && $receipt['amount_minor'] === $intent['amount_minor'] && $receipt['currency'] === $intent['currency']
            && $receipt['identity_hash'] === $identity, 'RECHARGE_RECEIPT_BINDING_INVALID');
        if ($row->receipt_id === null) {
            DB::table('tx_native_recharge')->where('id', $row->id)->update([
                'status' => 'paid', 'receipt_id' => $receipt['receipt_id'],
                'journal_id' => $journal['journal_id'], 'paid_at' => $receipt['occurred_at'],
            ]);
        } elseif ($isWallet) {
            $this->require((string) $row->journal_id === $journal['journal_id']
                && (string) $row->paid_at === $receipt['occurred_at'], 'RECHARGE_EVIDENCE_INVALID');
        }
        return ['decision' => $isWallet ? 'wallet_credit' : 'refund_liability',
            'recharge_id' => $intent['recharge_id'], 'receipt_id' => $receipt['receipt_id'],
            'journal_id' => $journal['journal_id'], 'amount_minor' => $intent['amount_minor'], 'currency' => $intent['currency']];
    }

    private function verify(object $row, array $installation, int $userId, bool $locked = false): void
    {
        $payload = [];
        foreach (['installation_id', 'user_id', 'payment_id', 'trade_no', 'request_key', 'amount_minor', 'currency', 'created_at'] as $field) {
            $payload[$field] = (string) $row->$field;
        }
        MoneyValue::minor($row->amount_minor, true);
        MoneyValue::currency($row->currency);
        $this->require($row->installation_id === $installation['installation_id'] && (string) $row->user_id === (string) $userId
            && hash_equals(hash('sha256', $row->request_key), $row->key_hash)
            && hash_equals(MoneyValue::hash($payload), $row->content_hash)
            && in_array($row->status, ['pending', 'paid'], true)
            && ($row->status === 'paid'
                ? $row->receipt_id !== null && $row->journal_id !== null && $row->paid_at !== null
                : $row->receipt_id === null && $row->journal_id === null && $row->paid_at === null),
            'RECHARGE_EVIDENCE_INVALID');
        if ($row->status === 'paid') {
            if ($locked) {
                $this->lockMoneyAccounts($installation, $userId, $row->currency);
            }
            $receipts = DB::table('tx_payment_receipt')->where('id', $row->receipt_id);
            $receipt = ($locked ? $receipts->lockForUpdate() : $receipts)->first();
            $this->require($receipt !== null && $receipt->intent_type === 'recharge'
                && (string) $receipt->recharge_id === (string) $row->id && (string) $receipt->user_id === (string) $userId
                && $receipt->installation_id === $row->installation_id
                && (string) $receipt->amount_minor === (string) $row->amount_minor && $receipt->currency === $row->currency
                && (string) $receipt->occurred_at === (string) $row->paid_at, 'RECHARGE_EVIDENCE_INVALID');
            $journal = $locked ? $this->ledger->inspectJournalLocked((int) $row->journal_id)
                : $this->ledger->inspectJournal((int) $row->journal_id);
            $this->require($journal['business_type'] === 'wallet_credit'
                && $journal['business_ref'] === (string) $receipt->attempt_id
                && $journal['idempotency_key'] === $receipt->identity_hash
                && $journal['installation_id'] === $row->installation_id && $journal['user_id'] === (string) $userId
                && $journal['currency'] === $row->currency && $journal['occurred_at'] === (string) $row->paid_at
                && $journal['postings'] === [
                    ['owner_id' => '0', 'purpose' => 'provider_clearing', 'side' => 'debit', 'amount_minor' => (string) $row->amount_minor],
                    ['owner_id' => (string) $userId, 'purpose' => 'wallet_available', 'side' => 'credit', 'amount_minor' => (string) $row->amount_minor],
                ], 'RECHARGE_EVIDENCE_INVALID');
        }
    }

    private function lockMoneyAccounts(array $installation, int $userId, string $currency): void
    {
        $this->ledger->lockAccountsLocked($installation, $userId, $currency, [
            ['owner_id' => 0, 'purpose' => 'provider_clearing'],
            ['owner_id' => 0, 'purpose' => 'refund_liability'],
            ['owner_id' => $userId, 'purpose' => 'wallet_available'],
        ]);
    }

    private function dto(object $row): array
    {
        return ['recharge_id' => (string) $row->id, 'trade_no' => $row->trade_no,
            'payment_method_id' => (string) $row->payment_id, 'amount_minor' => (string) $row->amount_minor,
            'fee_minor' => '0', 'total_minor' => (string) $row->amount_minor, 'currency' => $row->currency,
            'status' => $row->status, 'created_at' => (string) $row->created_at,
            'paid_at' => $row->paid_at === null ? null : (string) $row->paid_at];
    }

    private function context(array $installation, int $userId): void
    {
        $this->require(DB::transactionLevel() > 0 && $userId > 0
            && is_string($installation['installation_id'] ?? null), 'BILLING_TRANSACTION_REQUIRED');
    }

    private function require(bool $condition, string $error): void
    {
        if (!$condition) { throw new BillingError($error); }
    }
}
