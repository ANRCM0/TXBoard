<?php

namespace App\Domains\Billing;

use App\Domains\Installation\InstallationAuthority;
use App\Domains\Subscription\Contracts\OrderFulfillment;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Permanent free-order acceptance is SQL work, never a fabricated financial receipt. */
final class NativeFreeCheckout
{
    private const SCHEMA = 'native-free-checkout/1';
    private const RESULT = ['type' => -1, 'data' => true];

    public function start(int $userId, int $orderId, string $requestKey, array $input): array
    {
        $key = MoneyValue::key($requestKey);
        $requestHash = MoneyValue::hash(['schema' => self::SCHEMA, 'order_id' => (string) $orderId, 'input' => $input]);
        return app(PublishedOrderService::class)->transitionOrder($orderId,
            function (Order $order, OrderPurchaseTerms $purchase, array $installation) use (
                $userId, $key, $requestHash, $input
            ): array {
                $this->require((string) $order->user_id === (string) $userId, 'FREE_CHECKOUT_ORDER_MISSING');
                $this->require((string) $order->total_amount === '0'
                    && (int) ($order->handling_amount ?? 0) === 0, 'FREE_CHECKOUT_AMOUNT_INVALID');
                $row = DB::table('tx_native_free_checkout')->where('installation_id', $installation['installation_id'])
                    ->where('user_id', $userId)->where('key_hash', hash('sha256', $key))->lockForUpdate()->first();
                if ($row !== null) {
                    $this->require((string) $row->order_id === (string) $order->id && $row->request_key === $key
                        && hash_equals($row->request_hash, $requestHash), 'FREE_CHECKOUT_IDEMPOTENCY_CONFLICT');
                    return $this->verify($row, $order, $purchase, $installation);
                }
                $this->require(!DB::table('tx_native_free_checkout')->where('order_id', $order->id)->exists(),
                    'FREE_CHECKOUT_IDEMPOTENCY_CONFLICT');
                $this->require($input === ['method' => null, 'token_hash' => null, 'wallet' => false, 'wallet_minor' => null],
                    'FREE_CHECKOUT_INPUT_INVALID');
                $this->require(!(bool) DB::table('tx_user')->where('id', $userId)->value('banned'),
                    'FREE_CHECKOUT_ACCOUNT_UNAVAILABLE');
                $this->require((int) $order->status === Order::STATUS_PENDING, 'FREE_CHECKOUT_ORDER_NOT_PENDING');
                app(NativeUpgradeCredits::class)->lockSourcesLocked($installation, $order);
                $acceptedAt = time();
                app(NativeUpgradeCredits::class)->assertPayableLocked($installation, $order, $acceptedAt);
                $payload = [
                    'schema' => self::SCHEMA, 'installation_id' => $installation['installation_id'],
                    'user_id' => (string) $userId, 'order_id' => (string) $order->id, 'request_key' => $key,
                    'request_hash' => $requestHash, 'purchase_hash' => $purchase->content_hash,
                    'currency' => $purchase->currency, 'result' => self::RESULT, 'created_at' => (string) time(),
                ];
                $record = $payload;
                unset($record['schema'], $record['result']);
                DB::table('tx_native_free_checkout')->insert($record + [
                    'key_hash' => hash('sha256', $key), 'result_json' => json_encode(self::RESULT, JSON_THROW_ON_ERROR),
                    'content_hash' => MoneyValue::hash($payload), 'status' => 'pending',
                ]);
                $order->status = Order::STATUS_PROCESSING;
                $order->paid_at = (int) $payload['created_at'];
                $order->callback_no = $order->trade_no;
                $order->saveOrFail();
                app(NativeUpgradeCredits::class)->fulfillAcceptedLocked($installation, $order, $purchase, $acceptedAt);
                return self::RESULT;
            });
    }

    public function recover(int $limit): array
    {
        if (DB::transactionLevel() !== 0) { throw new BillingError('BILLING_OUTER_TRANSACTION_UNSUPPORTED'); }
        $this->require($limit >= 1 && $limit <= 1000, 'BILLING_WORK_LIMIT_INVALID');
        app(InstallationAuthority::class)->assertNativeRuntime();
        $items = DB::table('tx_native_free_checkout')->useWritePdo()->where('status', 'pending')
            ->orderBy('id')->limit($limit)->get();
        $results = [];
        foreach ($items as $locator) {
            try {
                $this->withRecord($locator);
                // This owns its own transaction and unique order.fulfill operation; Provider I/O follows commit.
                app(OrderFulfillment::class)->fulfill((int) $locator->order_id);
                $this->withRecord($locator, true);
                $results['free:'.$locator->id] = ['status' => 'completed'];
            } catch (Throwable $error) {
                $code = $error instanceof BillingError ? $error->errorCode : $error->getMessage();
                $results['free:'.$locator->id] = ['status' => 'pending', 'error_code' =>
                    preg_match('/\A[A-Z][A-Z0-9_]{0,79}\z/D', $code) === 1 ? $code : 'FREE_CHECKOUT_FULFILLMENT_PENDING'];
            }
        }
        return $results;
    }

    private function withRecord(object $locator, bool $completed = false): void
    {
        app(PublishedOrderService::class)->transitionOrder((int) $locator->order_id,
            function (Order $order, OrderPurchaseTerms $purchase, array $installation) use ($locator, $completed): void {
                $row = DB::table('tx_native_free_checkout')->where('id', $locator->id)->lockForUpdate()->first();
                $this->require($row !== null && (string) $row->order_id === (string) $locator->order_id
                    && (string) $row->user_id === (string) $locator->user_id, 'FREE_CHECKOUT_EVIDENCE_INVALID');
                $this->verify($row, $order, $purchase, $installation);
                if ($completed) {
                    $this->require((int) $order->status === Order::STATUS_COMPLETED, 'FREE_CHECKOUT_FULFILLMENT_INVALID');
                    DB::table('tx_native_free_checkout')->where('id', $row->id)->where('status', 'pending')
                        ->update(['status' => 'completed', 'completed_at' => time()]);
                }
            });
    }

    private function verify(object $row, Order $order, OrderPurchaseTerms $purchase, array $installation): array
    {
        try {
            $payload = ['schema' => self::SCHEMA];
            foreach (['installation_id', 'user_id', 'order_id', 'request_key', 'request_hash', 'purchase_hash',
                'currency', 'created_at'] as $field) { $payload[$field] = (string) $row->$field; }
            $payload['result'] = json_decode($row->result_json, true, 8, JSON_THROW_ON_ERROR);
            $this->require($row->installation_id === $installation['installation_id']
                && (string) $row->user_id === (string) $order->user_id && (string) $row->order_id === (string) $order->id
                && $row->purchase_hash === $purchase->content_hash && $row->currency === $purchase->currency
                && (string) $order->total_amount === '0' && (int) ($order->handling_amount ?? 0) === 0
                && hash_equals(hash('sha256', MoneyValue::key($row->request_key)), $row->key_hash)
                && hash_equals(MoneyValue::hash($payload), $row->content_hash)
                && $payload['result'] === self::RESULT && in_array($row->status, ['pending', 'completed'], true)
                && in_array((int) $order->status, [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED], true)
                && (string) $order->callback_no === (string) $order->trade_no
                && (string) $order->paid_at === (string) $row->created_at
                && ($row->status !== 'completed' || ((int) $order->status === Order::STATUS_COMPLETED
                    && $row->completed_at !== null)), 'FREE_CHECKOUT_EVIDENCE_INVALID');
            return $payload['result'];
        } catch (Throwable) {
            throw new BillingError('FREE_CHECKOUT_EVIDENCE_INVALID');
        }
    }

    private function require(bool $condition, string $code): void
    {
        if (!$condition) { throw new BillingError($code); }
    }
}
