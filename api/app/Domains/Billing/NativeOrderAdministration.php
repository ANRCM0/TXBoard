<?php

namespace App\Domains\Billing;

use App\Core\Kernel\Execution\CurrentInvocation;
use App\Domains\Billing\Contracts\AdminOrderCommands;
use App\Domains\Billing\Contracts\OrderPage;
use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Notifications\Internal\EventJournal;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Extensions\Contracts\DomainEvent;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/** Account-first commands and durable audit; Redis only wakes the existing order recovery. */
final class NativeOrderAdministration implements AdminOrderCommands
{
    public function __construct(private readonly CurrentSessionAuthorization $sessions,
        private readonly NativeSubscriptionStore $subscriptions, private readonly PurchasedOrderGrant $grants,
        private readonly NativeWalletReservations $wallet, private readonly NativeOrderEventJournal $orders,
        private readonly EventJournal $events, private readonly CurrentInvocation $invocation) {}

    public function execute(int $actorId, string $action, array $input, string $key): array
    {
        $this->fresh();
        if ($actorId < 1 || !in_array($action, ['close', 'retry'], true) || !Str::isUuid($key)
            || count($input) !== 3 || array_diff(['trade_no', 'expected_status', 'reason'], array_keys($input))
            || !self::trade($input['trade_no'] ?? null)
            || ($input['expected_status'] ?? null) !== ($action === 'close' ? Order::STATUS_PENDING : Order::STATUS_PROCESSING)
            || !is_string($input['reason'] ?? null) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}\z/D', $input['reason']) !== 1) {
            throw new InvalidArgumentException('ADMIN_ORDER_INPUT_INVALID');
        }
        $locator = $this->locate($input['trade_no']);
        $result = DB::transaction(function () use ($actorId, $action, $input, $key, $locator): array {
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            $this->accounts($actorId, (int) ($locator->user_id ?? 0));
            $this->sessions->assertCurrentLocked($actorId, 'commerce.orders.manage');
            if ($locator === null) throw new BillingError('ADMIN_ORDER_MISSING');
            [, , $subscriptions] = $this->subscriptions->lockAccount((int) $locator->user_id, time(), $installation['installation_id']);
            foreach ($subscriptions as $subscription) $this->subscriptions->lockHistory($subscription);
            $order = Order::query()->whereKey($locator->id)->lockForUpdate()->first();
            $this->identity($order, $locator);
            $purchase = OrderPurchaseTerms::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if ($purchase === null || $this->grants->resolve($order, $purchase) === null) {
                throw new LogicException('ADMIN_ORDER_PURCHASE_INVALID');
            }
            $inputHash = MoneyValue::hash($input);
            $keyHash = hash('sha256', $installation['installation_id'].':'.$actorId.':'.$action.':'.strtolower($key));
            $old = DB::table('tx_native_order_admin_request')->where('key_hash', $keyHash)->lockForUpdate()->first();
            if ($old !== null) {
                if ((int) $old->actor_id !== $actorId || $old->action !== $action) throw new LogicException('ADMIN_ORDER_RECEIPT_INVALID');
                if (!hash_equals($old->input_hash, $inputHash)) throw new BillingError('ADMIN_ORDER_IDEMPOTENCY_CONFLICT');
                $payload = $this->evidence($old, $installation, $order);
                return $this->result($old, $payload, $order);
            }
            if ((int) $order->status !== $input['expected_status']) throw new BillingError('ADMIN_ORDER_STATUS_CONFLICT');
            if ($action === 'close') {
                // A channel can still report payment after an unknown response. Keep that order open.
                if (DB::table('tx_payment_attempt')->where('order_id', $order->id)
                    ->where('status', '<>', 'failed')->sharedLock()->exists()) {
                    throw new BillingError('ADMIN_ORDER_PAYMENT_UNCONFIRMED');
                }
                $this->wallet->releaseLocked($installation, (int) $order->user_id, $order, $purchase);
                $order->status = Order::STATUS_CANCELLED;
                $order->saveOrFail();
                $this->orders->recordLocked($order, 'cancelled');
            }
            $now = time();
            $payload = ['schema' => 'native-admin-order-command/v1', 'installation_id' => $installation['installation_id'],
                'order_id' => (string) $order->id, 'trade_no' => (string) $order->trade_no, 'user_id' => (string) $order->user_id,
                'actor_id' => (string) $actorId, 'action' => $action, 'input_hash' => $inputHash,
                'expected_status' => $input['expected_status'], 'accepted_status' => (int) $order->status,
                'reason' => $input['reason'], 'occurred_at' => $now];
            $id = DB::table('tx_native_order_admin_request')->insertGetId(['installation_id' => $installation['installation_id'],
                'actor_id' => $actorId, 'order_id' => $order->id, 'action' => $action, 'key_hash' => $keyHash,
                'input_hash' => $inputHash, 'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
                'content_hash' => MoneyValue::hash($payload), 'created_at' => $now]);
            $record = DB::table('tx_native_order_admin_request')->where('id', $id)->first();
            $this->events->appendLocked(new DomainEvent(hash('sha256', $installation['installation_id'].':admin-order:'.$keyHash),
                $installation['installation_id'], 'billing.order.admin-command.v1', 'order:'.$order->id,
                $this->auditItem($record, $payload), $now,
                $this->invocation->current()?->correlationId ?? (string) Str::uuid()));
            return $this->result($record, $payload, $order);
        }, 3);
        if ($action === 'retry' && $result['status'] === Order::STATUS_PROCESSING) {
            try {
                Bus::dispatch((new OrderHandleJob($result['trade_no']))->onConnection('redis'));
            } catch (\Throwable) {
                // PROCESSING is the durable outbox. check:order retries it even if this wakeup is lost.
                Log::warning('ADMIN_ORDER_RECOVERY_WAKEUP_DEFERRED');
            }
        }
        return $result;
    }

    public function audit(int $actorId, string $tradeNo, string $afterId, int $limit): ?OrderPage
    {
        $this->fresh();
        if ($actorId < 1 || !self::trade($tradeNo) || !self::cursor($afterId) || $limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('ADMIN_ORDER_QUERY_INVALID');
        }
        $locator = $this->locate($tradeNo);
        return DB::transaction(function () use ($actorId, $locator, $afterId, $limit): ?OrderPage {
            $installation = app(InstallationAuthority::class)->assertNativeRead();
            $this->accounts($actorId, (int) ($locator->user_id ?? 0));
            $this->sessions->assertCurrentLocked($actorId, 'commerce.orders.read');
            if ($locator === null) return null;
            $order = Order::query()->whereKey($locator->id)->sharedLock()->first();
            $this->identity($order, $locator);
            $rows = DB::table('tx_native_order_admin_request')->where('installation_id', $installation['installation_id'])
                ->where('order_id', $order->id)->where('id', '>', $afterId)->orderBy('id')->limit($limit + 1)->sharedLock()->get();
            $items = $rows->take($limit)->map(fn (object $row): array => $this->auditItem($row, $this->evidence($row, $installation, $order)))->all();
            return new OrderPage($items, ['next_after_id' => $items === [] ? $afterId : $items[array_key_last($items)]['id'],
                'has_more' => $rows->count() > $limit]);
        }, 3);
    }

    private function accounts(int $actorId, int $ownerId): void
    {
        $ids = array_unique(array_filter([$actorId, $ownerId], static fn (int $id): bool => $id > 0));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            if (DB::table('tx_user')->where('id', $id)->lockForUpdate()->first() === null) {
                throw new BillingError('BILLING_ACCOUNT_MISSING');
            }
        }
    }

    private function locate(string $tradeNo): ?Order
    {
        return Order::query()->useWritePdo()->where('trade_no', $tradeNo)
            ->where('purchase_terms_version', PublishedOrderService::VERSION)->first(['id', 'user_id', 'trade_no']);
    }

    private function identity(?Order $order, Order $locator): void
    {
        if ($order === null || (string) $order->user_id !== (string) $locator->user_id
            || (string) $order->trade_no !== (string) $locator->trade_no
            || $order->purchase_terms_version !== PublishedOrderService::VERSION) {
            throw new LogicException('ADMIN_ORDER_IDENTITY_INVALID');
        }
    }

    private function evidence(object $row, array $installation, Order $order): array
    {
        $payload = json_decode($row->payload_json, true, 16, JSON_THROW_ON_ERROR);
        $fields = ['schema', 'installation_id', 'order_id', 'trade_no', 'user_id', 'actor_id', 'action',
            'input_hash', 'expected_status', 'accepted_status', 'reason', 'occurred_at'];
        if (!is_array($payload) || count($payload) !== count($fields) || array_diff($fields, array_keys($payload))
            || !hash_equals($row->content_hash, MoneyValue::hash($payload))
            || $payload['schema'] !== 'native-admin-order-command/v1'
            || $row->installation_id !== $installation['installation_id'] || $payload['installation_id'] !== $row->installation_id
            || $payload['order_id'] !== (string) $order->id || (string) $row->order_id !== (string) $order->id
            || $payload['trade_no'] !== $order->trade_no || $payload['user_id'] !== (string) $order->user_id
            || $payload['actor_id'] !== (string) $row->actor_id || !self::cursor($payload['actor_id']) || $payload['actor_id'] === '0'
            || $payload['action'] !== $row->action || !in_array($row->action, ['close', 'retry'], true)
            || $payload['input_hash'] !== $row->input_hash
            || $payload['expected_status'] !== ($row->action === 'close' ? Order::STATUS_PENDING : Order::STATUS_PROCESSING)
            || $payload['accepted_status'] !== ($row->action === 'close' ? Order::STATUS_CANCELLED : Order::STATUS_PROCESSING)
            || !is_string($payload['reason']) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}\z/D', $payload['reason']) !== 1
            || !is_int($payload['occurred_at']) || $payload['occurred_at'] < 1 || (string) $payload['occurred_at'] !== (string) $row->created_at
            || !hash_equals($payload['input_hash'], MoneyValue::hash(['trade_no' => $payload['trade_no'],
                'expected_status' => $payload['expected_status'], 'reason' => $payload['reason']]))) {
            throw new LogicException('ADMIN_ORDER_RECEIPT_INVALID');
        }
        if ($row->action === 'close' && (int) $order->status !== Order::STATUS_CANCELLED) {
            throw new LogicException('ADMIN_ORDER_RECEIPT_STATE_INVALID');
        }
        return $payload;
    }

    private function result(object $row, array $payload, Order $order): array
    {
        return ['request_id' => (string) $row->id, 'order_id' => (string) $order->id, 'trade_no' => $order->trade_no,
            'action' => $payload['action'], 'status' => (int) $order->status,
            'accepted_at' => self::date($payload['occurred_at'])];
    }

    private function auditItem(object $row, array $payload): array
    {
        return ['id' => (string) $row->id, 'order_id' => $payload['order_id'], 'actor_id' => $payload['actor_id'],
            'action' => $payload['action'], 'from_status' => $payload['expected_status'], 'to_status' => $payload['accepted_status'],
            'reason' => $payload['reason'], 'occurred_at' => self::date($payload['occurred_at'])];
    }

    private static function date(int $time): string { return Carbon::createFromTimestampUTC($time)->toIso8601String(); }
    private static function trade(mixed $value): bool { return is_string($value) && preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $value) === 1; }
    private static function cursor(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $value) === 1
            && (strlen($value) < 19 || strcmp($value, (string) PHP_INT_MAX) <= 0);
    }
    private function fresh(): void
    {
        if (DB::transactionLevel() !== 0) throw new LogicException('ADMIN_ORDER_OUTER_TRANSACTION_UNSUPPORTED');
    }
}
