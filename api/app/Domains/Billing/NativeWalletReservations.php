<?php

namespace App\Domains\Billing;

use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use Illuminate\Support\Facades\DB;

/** Order-owned native money reservations. Callers hold account, subscription and order locks. */
final class NativeWalletReservations
{
    public function __construct(private readonly NativeMoneyLedger $ledger, private readonly PurchasedOrderGrant $grants) {}

    public function reserveLocked(array $installation, int $userId, Order $order, OrderPurchaseTerms $terms,
        string $amountMinor, string $requestKey): array
    {
        $purchase = $this->purchase($installation, $userId, $order, $terms);
        $amount = MoneyValue::minor($amountMinor, true);
        $key = MoneyValue::key($requestKey);
        $this->require(bccomp($amount, MoneyValue::minor($purchase['price_minor'], true)) <= 0, 'WALLET_AMOUNT_EXCEEDS_PRICE');
        $existing = DB::table('tx_wallet_order_reservation')->where('installation_id', $installation['installation_id'])
            ->where('user_id', $userId)->where('key_hash', hash('sha256', $key))->first();
        if ($existing !== null) {
            $this->require((string) $existing->order_id === (string) $order->id && $existing->request_key === $key
                && (string) $existing->amount_minor === $amount,
                'WALLET_IDEMPOTENCY_CONFLICT');
        } else {
            $existing = DB::table('tx_wallet_order_reservation')->where('order_id', $order->id)->first();
            $this->require($existing === null || ($existing->request_key === $key
                && (string) $existing->amount_minor === $amount), 'WALLET_IDEMPOTENCY_CONFLICT');
        }
        if ($existing === null) {
            $this->require((int) $order->status === Order::STATUS_PENDING
                && !DB::table('tx_order_payment')->where('order_id', $order->id)->exists(), 'WALLET_ORDER_NOT_PAYABLE');
            $this->require(!DB::table('tx_payment_attempt')->where('order_id', $order->id)->exists(),
                'WALLET_CHANNEL_PAYMENT_PENDING');
            $payload = [
                'installation_id' => $installation['installation_id'], 'user_id' => (string) $userId,
                'order_id' => (string) $order->id, 'purchase_terms_id' => (string) $terms->id,
                'purchase_content_hash' => $purchase['content_hash'], 'request_key' => $key,
                'amount_minor' => $amount, 'currency' => MoneyValue::currency($purchase['currency']),
                'created_at' => (string) time(),
            ];
            $journal = $this->ledger->postLocked($installation, $userId, $this->journal($payload, 'reserve'));
            $id = DB::table('tx_wallet_order_reservation')->insertGetId($payload + [
                'key_hash' => hash('sha256', $key), 'content_hash' => MoneyValue::hash($payload),
                'status' => 'reserved', 'reserve_journal_id' => $journal['journal_id'],
            ]);
            $existing = DB::table('tx_wallet_order_reservation')->where('id', $id)->first();
        }
        return $this->verifyLocked($installation, $userId, $order, $terms, $existing);
    }

    public function channelAmountLocked(array $installation, int $userId, Order $order, OrderPurchaseTerms $terms): string
    {
        $purchase = $this->purchase($installation, $userId, $order, $terms);
        $row = DB::table('tx_wallet_order_reservation')->where('order_id', $order->id)->first();
        if ($row === null) { return MoneyValue::minor($purchase['price_minor'], true); }
        $result = $this->verifyLocked($installation, $userId, $order, $terms, $row);
        return $result['channel_minor'];
    }

    public function captureLocked(array $installation, int $userId, Order $order, OrderPurchaseTerms $terms): array
    {
        $row = DB::table('tx_wallet_order_reservation')->where('order_id', $order->id)->first();
        $this->require($row !== null, 'WALLET_RESERVATION_MISSING');
        $result = $this->verifyLocked($installation, $userId, $order, $terms, $row);
        $this->require($row->status !== 'released', 'WALLET_RESERVATION_RELEASED');
        if ($row->status === 'reserved') {
            $this->require((int) $order->status === Order::STATUS_PENDING, 'WALLET_ORDER_NOT_PAYABLE');
            $payload = $this->payload($row);
            $payload['occurred_at'] = (string) time();
            $journal = $this->ledger->postLocked($installation, $userId, $this->journal($payload, 'capture'));
            DB::table('tx_wallet_order_reservation')->where('id', $row->id)->update([
                'status' => 'captured', 'capture_journal_id' => $journal['journal_id'], 'captured_at' => $payload['occurred_at'],
            ]);
            $result = $this->verifyLocked($installation, $userId, $order, $terms,
                DB::table('tx_wallet_order_reservation')->where('id', $row->id)->first());
        }
        return $result;
    }

    public function releaseLocked(array $installation, int $userId, Order $order, ?OrderPurchaseTerms $terms = null): ?array
    {
        $row = DB::table('tx_wallet_order_reservation')->where('order_id', $order->id)->first();
        if ($row === null) { return null; }
        $terms ??= OrderPurchaseTerms::query()->where('order_id', $order->id)->lockForUpdate()->first();
        $this->require($terms !== null, 'WALLET_PURCHASE_REQUIRED');
        $result = $this->verifyLocked($installation, $userId, $order, $terms, $row);
        $this->require($row->status !== 'captured', 'WALLET_PAYMENT_ALREADY_CAPTURED');
        if ($row->status === 'reserved') {
            $payload = $this->payload($row);
            $payload['occurred_at'] = (string) time();
            $journal = $this->ledger->postLocked($installation, $userId, $this->journal($payload, 'release'));
            DB::table('tx_wallet_order_reservation')->where('id', $row->id)->update([
                'status' => 'released', 'release_journal_id' => $journal['journal_id'], 'released_at' => $payload['occurred_at'],
            ]);
            $result = $this->verifyLocked($installation, $userId, $order, $terms,
                DB::table('tx_wallet_order_reservation')->where('id', $row->id)->first());
        }
        return $result;
    }

    public function verifyLocked(array $installation, int $userId, Order $order, OrderPurchaseTerms $terms, object $row): array
    {
        $purchase = $this->purchase($installation, $userId, $order, $terms);
        $payload = $this->payload($row);
        $this->require($payload['installation_id'] === $installation['installation_id'] && $payload['user_id'] === (string) $userId
            && $payload['order_id'] === (string) $order->id && $payload['purchase_terms_id'] === (string) $terms->id
            && $payload['purchase_content_hash'] === $purchase['content_hash']
            && bccomp(MoneyValue::minor($payload['amount_minor'], true), MoneyValue::minor($purchase['price_minor'], true)) <= 0
            && $payload['currency'] === MoneyValue::currency($purchase['currency'])
            && hash_equals(MoneyValue::hash($payload), $row->content_hash)
            && hash_equals(hash('sha256', $row->request_key), $row->key_hash)
            && in_array($row->status, ['reserved', 'captured', 'released'], true), 'WALLET_EVIDENCE_INVALID');
        foreach (['reserve', 'capture', 'release'] as $kind) {
            $expected = $kind === 'reserve' || ($kind === 'capture' ? $row->status === 'captured' : $row->status === 'released');
            $journalId = $row->{$kind.'_journal_id'};
            $at = $kind === 'reserve' ? $row->created_at : $row->{($kind === 'capture' ? 'captured' : 'released').'_at'};
            $this->require($expected === ($journalId !== null) && $expected === ($at !== null), 'WALLET_EVIDENCE_INVALID');
            if ($expected) {
                $journal = $this->ledger->inspectJournal((int) $journalId);
                $expectedJournal = $this->journal($payload + ['occurred_at' => (string) $at], $kind);
                $this->require($journal['installation_id'] === $installation['installation_id']
                    && $journal['user_id'] === (string) $userId, 'WALLET_EVIDENCE_INVALID');
                foreach ($expectedJournal as $field => $value) {
                    $this->require($journal[$field] === $value, 'WALLET_EVIDENCE_INVALID');
                }
            }
        }
        return ['reservation_id' => (string) $row->id, 'wallet_minor' => $payload['amount_minor'],
            'channel_minor' => MoneyValue::add($purchase['price_minor'], '-'.$payload['amount_minor']),
            'currency' => $payload['currency'], 'status' => $row->status,
            'reserve_journal_id' => (string) $row->reserve_journal_id,
            'capture_journal_id' => $row->capture_journal_id === null ? null : (string) $row->capture_journal_id,
            'captured_at' => $row->captured_at === null ? null : (string) $row->captured_at];
    }

    private function journal(array $payload, string $kind): array
    {
        [$debitOwner, $debit, $creditOwner, $credit] = match ($kind) {
            'reserve' => [$payload['user_id'], 'wallet_available', $payload['user_id'], 'wallet_reserved'],
            'capture' => [$payload['user_id'], 'wallet_reserved', '0', 'sales_revenue'],
            'release' => [$payload['user_id'], 'wallet_reserved', $payload['user_id'], 'wallet_available'],
        };
        return ['business_type' => 'wallet_'.$kind, 'business_ref' => $payload['order_id'],
            'idempotency_key' => $payload['request_key'], 'currency' => $payload['currency'],
            'occurred_at' => $payload['occurred_at'] ?? $payload['created_at'], 'postings' => [
                ['owner_id' => $debitOwner, 'purpose' => $debit, 'side' => 'debit', 'amount_minor' => $payload['amount_minor']],
                ['owner_id' => $creditOwner, 'purpose' => $credit, 'side' => 'credit', 'amount_minor' => $payload['amount_minor']],
            ]];
    }

    private function payload(object $row): array
    {
        $payload = [];
        foreach (['installation_id', 'user_id', 'order_id', 'purchase_terms_id', 'purchase_content_hash',
            'request_key', 'amount_minor', 'currency', 'created_at'] as $field) {
            $payload[$field] = (string) $row->$field;
        }
        return $payload;
    }

    private function purchase(array $installation, int $userId, Order $order, OrderPurchaseTerms $terms): array
    {
        $this->require(DB::transactionLevel() > 0 && $order->purchase_terms_version === 'tx-core.purchase-terms/v2'
            && (string) $order->user_id === (string) $userId && (string) $terms->order_id === (string) $order->id
            && is_string($installation['installation_id'] ?? null), 'WALLET_OWNERSHIP_INVALID');
        $purchase = $this->grants->resolve($order, $terms);
        $this->require($purchase !== null && (int) ($order->handling_amount ?? 0) === 0, 'WALLET_PURCHASE_REQUIRED');
        return $purchase;
    }

    private function require(bool $condition, string $error): void
    {
        if (!$condition) { throw new BillingError($error); }
    }
}
