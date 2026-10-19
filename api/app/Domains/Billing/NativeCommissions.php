<?php

namespace App\Domains\Billing;

use App\Core\Kernel\Execution\CurrentInvocation;
use App\Domains\Billing\Contracts\Commissions;
use App\Domains\Billing\Contracts\OrderPage;
use App\Domains\Billing\Internal\CommissionTerms;
use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\Identity\Internal\ReferralSnapshot;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Notifications\Internal\EventJournal;
use App\Domains\Subscription\Services\NativeSubscriptionStore;
use App\Extensions\Contracts\DomainEvent;
use App\Models\Order;
use App\Models\OrderPurchaseTerms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Single-level commissions derived from native paid orders, never legacy balances. */
final class NativeCommissions implements Commissions, CommissionTerms
{
    public const REFUND_POLICY = 'net-before-vesting-retained-after/v1';

    public function __construct(private readonly CurrentSessionAuthorization $sessions,
        private readonly ReferralSnapshot $referrals, private readonly NativeMoneyLedger $ledger,
        private readonly NativeSubscriptionStore $subscriptions, private readonly EventJournal $events,
        private readonly CurrentInvocation $invocation) {}

    public function policy(int $actorId, bool $admin): array
    {
        return $this->owned($actorId, $admin, false, [], fn (array $installation): array => $this->policyLocked($installation));
    }

    public function balance(int $actorId): array
    {
        return $this->owned($actorId, false, false, [], function (array $installation) use ($actorId): array {
            $balances = ['available_minor' => '0', 'reserved_minor' => '0', 'currency' => 'CNY'];
            foreach (DB::table('tx_money_account')->where('installation_id', $installation['installation_id'])->where('owner_id', $actorId)
                ->where('currency', 'CNY')->whereIn('purpose', ['commission_available', 'commission_reserved'])->sharedLock()->get() as $account) {
                if ((int) $account->user_id !== $actorId || $account->normal_side !== 'credit') throw new LogicException('COMMISSION_BALANCE_INVALID');
                $balances[$account->purpose === 'commission_available' ? 'available_minor' : 'reserved_minor'] = MoneyValue::minor((string) $account->balance_minor);
            }
            return $balances;
        });
    }

    public function listing(int $actorId, bool $admin, string $kind, int $page, int $perPage): OrderPage
    {
        if (!in_array($kind, ['rewards', 'payouts'], true) || $page < 1 || $page > 100000 || $perPage < 1 || $perPage > 50) self::invalid();
        return $this->owned($actorId, $admin, false, [], function (array $installation) use ($actorId, $admin, $kind, $page, $perPage): OrderPage {
            $query = DB::table($kind === 'rewards' ? 'tx_native_commission_order' : 'tx_native_commission_payout')->where('installation_id', $installation['installation_id']);
            if (!$admin) $query->where($kind === 'rewards' ? 'sponsor_id' : 'user_id', $actorId);
            $rows = $query->orderByDesc('created_at')->orderBy('id')->sharedLock()->paginate($perPage, ['*'], 'page', $page);
            return new OrderPage(array_map(function (object $row) use ($kind, $installation): array {
                if ($kind === 'rewards') return $this->rewardDto($row);
                $this->verifyPayout($installation, $row);
                return $this->payoutDto($row);
            }, $rows->items()),
                ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()]);
        });
    }

    public function execute(int $actorId, bool $admin, string $action, array $input, string $key): array
    {
        if (!Str::isUuid($key) || !in_array($action, $admin ? ['policy', 'approve', 'reject'] : ['payout'], true)) self::invalid();
        $locator = null;
        if ($action === 'policy') {
            if (count($input) !== 5 || !is_bool($input['enabled'] ?? null) || !is_int($input['rate_bps'] ?? null)
                || $input['rate_bps'] < 1 || $input['rate_bps'] > 10000 || !is_int($input['hold_seconds'] ?? null)
                || $input['hold_seconds'] < 0 || $input['hold_seconds'] > 90*86400 || ($input['refund_policy'] ?? null) !== self::REFUND_POLICY) self::invalid();
            self::decimal($input['expected_version'] ?? null);
        } elseif ($action === 'payout') {
            if (count($input) !== 2 || ($input['currency'] ?? null) !== 'CNY' || !is_string($input['amount_minor'] ?? null)
                || bccomp(MoneyValue::minor($input['amount_minor'], true), '2147483647', 0) > 0) self::invalid();
        } else {
            if (count($input) !== 3 || !Str::isUuid($input['payout_id'] ?? '') || !is_string($input['reason'] ?? null)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $input['reason']) !== 1) self::invalid();
            self::decimal($input['expected_version'] ?? null, true);
            $locator = DB::table('tx_native_commission_payout')->useWritePdo()->where('id', $input['payout_id'])->first(['user_id']);
            if ($locator === null) throw new BillingError('COMMISSION_PAYOUT_MISSING');
        }
        return $this->owned($actorId, $admin, true, $locator === null ? [] : [(int) $locator->user_id],
            function (array $installation) use ($actorId, $admin, $action, $input, $key, $locator): array {
                $inputHash = MoneyValue::hash($input);
                $keyHash = hash('sha256', $installation['installation_id'].':'.$actorId.':commission.'.$action.':'.strtolower($key));
                $old = DB::table('tx_native_commission_request')->where('key_hash', $keyHash)->lockForUpdate()->first();
                if ($old !== null) {
                    if ($old->installation_id !== $installation['installation_id'] || (int) $old->actor_id !== $actorId || $old->action !== $action) throw new LogicException('COMMISSION_RECEIPT_INVALID');
                    if (!hash_equals($old->input_hash, $inputHash)) throw new BillingError('COMMISSION_IDEMPOTENCY_CONFLICT');
                    if (!hash_equals($old->content_hash, hash('sha256', $inputHash.':'.$old->response_json))) throw new LogicException('COMMISSION_RECEIPT_INVALID');
                    return json_decode($old->response_json, true, 16, JSON_THROW_ON_ERROR);
                }
                $now = time();
                if ($action === 'policy') {
                    DB::table('tx_native_commission_policy_head')->insertOrIgnore(['id' => 1, 'installation_id' => $installation['installation_id'], 'version' => 0]);
                    $head = DB::table('tx_native_commission_policy_head')->where('id', 1)->lockForUpdate()->first();
                    $current = $this->policyLocked($installation);
                    if ($current['row_version'] !== $input['expected_version'] || (string) $head->version === MoneyValue::MAX) throw new BillingError('COMMISSION_VERSION_CONFLICT');
                    $result = ['enabled' => $input['enabled'], 'rate_bps' => $input['rate_bps'], 'hold_seconds' => $input['hold_seconds'],
                        'refund_policy' => self::REFUND_POLICY, 'row_version' => (string) ((int) $head->version+1)];
                    DB::table('tx_native_commission_policy')->insert(['version' => $result['row_version'], 'installation_id' => $installation['installation_id'],
                        'payload_json' => json_encode($result, JSON_THROW_ON_ERROR), 'content_hash' => MoneyValue::hash($result), 'created_at' => $now]);
                    DB::table('tx_native_commission_policy_head')->where('id', 1)->update(['version' => $result['row_version']]);
                    $this->event($installation, 'policy:'.$result['row_version'], 'policy', $actorId, $result, $now);
                } else {
                    $userId = $action === 'payout' ? $actorId : (int) $locator->user_id;
                    if ($action === 'payout') {
                        $id = (string) Str::uuid();
                        $terms = ['id' => $id, 'installation_id' => $installation['installation_id'], 'user_id' => (string) $userId,
                            'amount_minor' => $input['amount_minor'], 'currency' => 'CNY', 'created_at' => (string) $now, 'destination' => 'native-wallet'];
                        $this->lockMoney($installation, [$userId]);
                        $journal = $this->payoutPost($installation, $terms, 'reserve', $now);
                        DB::table('tx_native_commission_payout')->insert(['id' => $id, 'installation_id' => $installation['installation_id'], 'user_id' => $userId,
                            'terms_json' => json_encode($terms, JSON_THROW_ON_ERROR), 'terms_hash' => MoneyValue::hash($terms), 'state' => 'requested',
                            'row_version' => 1, 'reserve_journal_id' => $journal['journal_id'], 'created_at' => $now]);
                        $row = DB::table('tx_native_commission_payout')->where('id', $id)->lockForUpdate()->first();
                    } else {
                        $row = DB::table('tx_native_commission_payout')->where('id', $input['payout_id'])->lockForUpdate()->first();
                        if ($row === null || $row->installation_id !== $installation['installation_id'] || (int) $row->user_id !== $userId) throw new LogicException('COMMISSION_PAYOUT_INVALID');
                        $this->lockMoney($installation, [$userId]);
                        $terms = $this->verifyPayout($installation, $row);
                        if ($row->state !== 'requested' || (string) $row->row_version !== $input['expected_version']) throw new BillingError('COMMISSION_VERSION_CONFLICT');
                        $journal = $this->payoutPost($installation, $terms, $action, $now);
                        $change = ['state' => $action === 'approve' ? 'approved' : 'rejected', 'row_version' => 2,
                            'decision_journal_id' => $journal['journal_id'], 'actor_id' => $actorId, 'reason' => $input['reason'], 'closed_at' => $now];
                        $terminal = $this->payoutTerminal((object) array_merge((array) $row, $change));
                        DB::table('tx_native_commission_payout_terminal')->insert(['payout_id' => $row->id,
                            'payload_json' => json_encode($terminal, JSON_THROW_ON_ERROR), 'content_hash' => MoneyValue::hash($terminal)]);
                        DB::table('tx_native_commission_payout')->where('id', $row->id)->update($change);
                        $row = (object) array_merge((array) $row, $change);
                    }
                    $this->verifyPayout($installation, $row);
                    $result = $this->payoutDto($row);
                    $this->event($installation, 'payout:'.$row->id.':'.$row->row_version, $action, $actorId, $result, $now);
                }
                $json = json_encode($result, JSON_THROW_ON_ERROR);
                DB::table('tx_native_commission_request')->insert(['key_hash' => $keyHash, 'input_hash' => $inputHash,
                    'installation_id' => $installation['installation_id'], 'actor_id' => $actorId, 'action' => $action,
                    'response_json' => $json, 'content_hash' => hash('sha256', $inputHash.':'.$json), 'created_at' => $now]);
                return $result;
            });
    }

    public function captureLocked(array $installation, Order $order, OrderPurchaseTerms $purchase): void
    {
        if (DB::transactionLevel() < 1 || (int) $order->user_id < 1 || (int) $purchase->order_id !== (int) $order->id) throw new LogicException('COMMISSION_TRANSACTION_REQUIRED');
        $policy = $this->policyLocked($installation);
        $referral = $this->referrals->forAccountLocked($installation['installation_id'], (int) $order->user_id);
        if (!$policy['enabled'] || $referral === null || $purchase->currency !== 'CNY' || (string) $purchase->price_minor === '0') return;
        $snapshot = ['schema' => 'native-commission-order/1', 'installation_id' => $installation['installation_id'],
            'order_id' => (string) $order->id, 'buyer_id' => (string) $order->user_id, 'sponsor_id' => $referral['sponsor_id'],
            'referral_hash' => $referral['content_hash'], 'purchase_id' => (string) $purchase->id, 'purchase_hash' => $purchase->content_hash,
            'price_minor' => MoneyValue::minor((string) $purchase->price_minor, true), 'currency' => 'CNY', 'policy' => $policy, 'created_at' => (string) time()];
        DB::table('tx_native_commission_order')->insert(['id' => (string) Str::uuid(), 'installation_id' => $installation['installation_id'],
            'order_id' => (int) $order->id, 'buyer_id' => (int) $order->user_id, 'sponsor_id' => (int) $referral['sponsor_id'],
            'snapshot_json' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'snapshot_hash' => MoneyValue::hash($snapshot),
            'state' => 'pending', 'policy_version' => $policy['row_version'], 'hold_seconds' => $policy['hold_seconds'], 'created_at' => (int) $snapshot['created_at']]);
    }

    /** Each candidate owns a transaction; restart or a duplicate wakeup cannot accrue twice. */
    public function recover(int $limit): array
    {
        if ($limit < 1 || $limit > 1000 || DB::transactionLevel() !== 0) self::invalid();
        app(InstallationAuthority::class)->assertNativeRuntime();
        $rows = DB::table('tx_native_commission_order as commission')->useWritePdo()->join('tx_order as orders', 'orders.id', '=', 'commission.order_id')
            ->where('commission.state', 'pending')->where(fn ($q) => $q->where('orders.status', Order::STATUS_CANCELLED)->orWhere(fn ($q) => $q->where('orders.status', Order::STATUS_COMPLETED)->whereRaw('orders.paid_at + commission.hold_seconds <= ?', [time()])))
            ->orderBy('commission.created_at')->orderBy('commission.id')->limit($limit)->get(['commission.*']);
        $results = [];
        foreach ($rows as $locator) {
            try {
                $results[$locator->id] = DB::transaction(function () use ($locator): array {
                    $installation = app(InstallationAuthority::class)->assertNativeMutation();
                    $users = array_unique([(int) $locator->buyer_id, (int) $locator->sponsor_id]); sort($users, SORT_NUMERIC);
                    foreach ($users as $userId) if (DB::table('tx_user')->where('id', $userId)->lockForUpdate()->first() === null) throw new LogicException('COMMISSION_ACCOUNT_MISSING');
                    [$buyer, $slot, $subscriptions] = $this->subscriptions->lockAccount((int) $locator->buyer_id, time(), $installation['installation_id']);
                    $histories = []; foreach ($subscriptions as $subscription) $histories[(string) $subscription->id] = $this->subscriptions->lockHistory($subscription);
                    $order = Order::query()->whereKey($locator->order_id)->lockForUpdate()->first();
                    $purchase = OrderPurchaseTerms::query()->where('order_id', $locator->order_id)->lockForUpdate()->first();
                    $row = DB::table('tx_native_commission_order')->where('id', $locator->id)->lockForUpdate()->first();
                    if ($row === null || $order === null || $purchase === null || (int) $row->buyer_id !== (int) $locator->buyer_id
                        || (int) $row->sponsor_id !== (int) $locator->sponsor_id || (int) $row->order_id !== (int) $locator->order_id || (int) $order->user_id !== (int) $row->buyer_id) throw new LogicException('COMMISSION_ORDER_INVALID');
                    $snapshot = $this->rewardSnapshot($row);
                    if ($row->installation_id !== $installation['installation_id'] || $snapshot['purchase_id'] !== (string) $purchase->id
                        || $snapshot['purchase_hash'] !== $purchase->content_hash || $snapshot['price_minor'] !== (string) $purchase->price_minor
                        || app(PurchasedOrderGrant::class)->resolve($order, $purchase) === null) throw new LogicException('COMMISSION_ORDER_INVALID');
                    if ($row->state !== 'pending') return $this->rewardDto($row);
                    $now = time(); $amount = '0'; $net = '0'; $journalId = null; $allocationId = null;
                    if ((int) $order->status === Order::STATUS_COMPLETED) {
                        if ($order->paid_at === null || $now < (int) $order->paid_at + $snapshot['policy']['hold_seconds']) return ['state' => 'pending'];
                        $this->lockMoney($installation, $users);
                        app(\App\Domains\Subscription\Internal\LockedOrderFulfillment::class)->applyLocked($installation, $buyer, $slot, $subscriptions, $histories, $order, $purchase, $now);
                        $proof = app(Internal\WinningPaymentProofs::class)->verifyWinningPaymentLocked($installation, (int) $row->buyer_id, $order, $purchase);
                        $allocationId = $proof['order_payment_id'];
                        $refunded = app(NativeRefundService::class)->refundedForPaymentLocked($installation, (int) $row->buyer_id, (int) $allocationId);
                        $net = MoneyValue::minor(bcsub($snapshot['price_minor'], $refunded, 0));
                        $amount = bcdiv(bcmul($net, (string) $snapshot['policy']['rate_bps'], 0), '10000', 0);
                        MoneyValue::minor($amount);
                        if ($amount !== '0') {
                            $journal = $this->ledger->postLocked($installation, (int) $row->sponsor_id, $this->rewardJournal($row, $amount, $now));
                            $journalId = $journal['journal_id'];
                        }
                    } elseif (!in_array((int) $order->status, [Order::STATUS_CANCELLED], true)) return ['state' => 'pending'];
                    $settlement = ['commission_id' => $row->id, 'installation_id' => $installation['installation_id'], 'snapshot_hash' => $row->snapshot_hash,
                        'order_payment_id' => $allocationId, 'net_minor' => $net, 'amount_minor' => $amount, 'journal_id' => $journalId, 'settled_at' => (string) $now];
                    DB::table('tx_native_commission_reward')->insert(['commission_id' => $row->id, 'installation_id' => $installation['installation_id'], 'sponsor_id' => (int) $row->sponsor_id, 'payload_json' => json_encode($settlement, JSON_THROW_ON_ERROR),
                        'content_hash' => MoneyValue::hash($settlement), 'journal_id' => $journalId]);
                    DB::table('tx_native_commission_order')->where('id', $row->id)->update(['state' => $amount === '0' ? 'ineligible' : 'accrued']);
                    $row->state = $amount === '0' ? 'ineligible' : 'accrued';
                    $this->event($installation, 'reward:'.$row->id, 'accrue', null, $this->rewardDto($row), $now);
                    return $this->rewardDto($row);
                }, 3);
            } catch (\Throwable $failure) {
                $code = $failure->getMessage();
                $results[$locator->id] = ['error_code' => preg_match('/^[A-Z][A-Z0-9_]{0,79}$/D', $code) === 1 ? $code : 'COMMISSION_RECOVERY_FAILED'];
            }
        }
        return $results;
    }

    private function policyLocked(array $installation): array
    {
        $head = DB::table('tx_native_commission_policy_head')->where('id', 1)->sharedLock()->first();
        $latest = DB::table('tx_native_commission_policy')->orderByDesc('version')->sharedLock()->first(['version']);
        if ($head !== null && $head->installation_id !== $installation['installation_id']) throw new LogicException('COMMISSION_POLICY_INVALID');
        if ((string) ($head->version ?? 0) !== (string) ($latest->version ?? 0)) throw new LogicException('COMMISSION_POLICY_INVALID');
        if ($head === null || (string) $head->version === '0') return ['enabled' => false, 'rate_bps' => 1000, 'hold_seconds' => 7*86400, 'refund_policy' => self::REFUND_POLICY, 'row_version' => '0'];
        $row = DB::table('tx_native_commission_policy')->where('version', $head->version)->sharedLock()->first();
        if ($head->installation_id !== $installation['installation_id'] || $row === null || $row->installation_id !== $installation['installation_id']) throw new LogicException('COMMISSION_POLICY_INVALID');
        $payload = json_decode($row->payload_json, true, 16, JSON_THROW_ON_ERROR);
        if (!hash_equals($row->content_hash, MoneyValue::hash($payload)) || ($payload['row_version'] ?? null) !== (string) $head->version) throw new LogicException('COMMISSION_POLICY_INVALID');
        $this->verifyPolicy($payload);
        return $payload;
    }
    private function verifyPolicy(array $policy): void
    {
        if (count($policy) !== 5 || !is_bool($policy['enabled'] ?? null) || !is_int($policy['rate_bps'] ?? null) || $policy['rate_bps'] < 1 || $policy['rate_bps'] > 10000
            || !is_int($policy['hold_seconds'] ?? null) || $policy['hold_seconds'] < 0 || $policy['hold_seconds'] > 90*86400
            || ($policy['refund_policy'] ?? null) !== self::REFUND_POLICY) throw new LogicException('COMMISSION_POLICY_INVALID');
        self::decimal($policy['row_version'] ?? null);
    }
    private function rewardSnapshot(object $row): array
    {
        $payload = json_decode($row->snapshot_json, true, 16, JSON_THROW_ON_ERROR);
        if (!hash_equals($row->snapshot_hash, MoneyValue::hash($payload)) || ($payload['schema'] ?? null) !== 'native-commission-order/1') throw new LogicException('COMMISSION_ORDER_INVALID');
        foreach (['installation_id', 'order_id', 'buyer_id', 'sponsor_id', 'created_at'] as $field) if (($payload[$field] ?? null) !== (string) $row->$field) throw new LogicException('COMMISSION_ORDER_INVALID');
        if ($payload['buyer_id'] === $payload['sponsor_id'] || $payload['currency'] !== 'CNY' || $payload['policy']['enabled'] !== true || (int) $row->hold_seconds !== ($payload['policy']['hold_seconds'] ?? null)) throw new LogicException('COMMISSION_ORDER_INVALID');
        MoneyValue::minor($payload['price_minor'], true); $this->verifyPolicy($payload['policy']);
        if (!$payload['policy']['enabled'] || (string) $row->policy_version !== $payload['policy']['row_version']) throw new LogicException('COMMISSION_ORDER_INVALID');
        $policy = DB::table('tx_native_commission_policy')->where('version', $row->policy_version)->sharedLock()->first();
        $referral = $this->referrals->forAccountLocked($row->installation_id, (int) $row->buyer_id);
        if ($policy === null || $policy->installation_id !== $row->installation_id || !hash_equals($policy->content_hash, MoneyValue::hash($payload['policy']))
            || !hash_equals($policy->content_hash, MoneyValue::hash(json_decode($policy->payload_json, true, 16, JSON_THROW_ON_ERROR)))
            || $referral === null || $referral['sponsor_id'] !== (string) $row->sponsor_id || !hash_equals($payload['referral_hash'], $referral['content_hash'])) throw new LogicException('COMMISSION_ORDER_INVALID');
        return $payload;
    }
    private function rewardDto(object $row): array
    {
        $snapshot = $this->rewardSnapshot($row);
        $reward = DB::table('tx_native_commission_reward')->where('commission_id', $row->id)->sharedLock()->first();
        if (($row->state === 'pending') !== ($reward === null) || !in_array($row->state, ['pending', 'accrued', 'ineligible'], true)) throw new LogicException('COMMISSION_REWARD_INVALID');
        $amount = '0'; $settled = null;
        if ($reward !== null) {
            $payload = json_decode($reward->payload_json, true, 16, JSON_THROW_ON_ERROR);
            if (!hash_equals($reward->content_hash, MoneyValue::hash($payload)) || $payload['commission_id'] !== $row->id || $payload['snapshot_hash'] !== $row->snapshot_hash
                || $payload['installation_id'] !== $row->installation_id || $reward->installation_id !== $row->installation_id || (string) $reward->sponsor_id !== (string) $row->sponsor_id || ($reward->journal_id === null ? null : (string) $reward->journal_id) !== $payload['journal_id']) throw new LogicException('COMMISSION_REWARD_INVALID');
            $amount = MoneyValue::minor($payload['amount_minor']); $settled = (int) $payload['settled_at'];
            if (($amount !== '0') !== ($reward->journal_id !== null) || $reward->journal_id !== null && DB::table('tx_money_journal')->where('reverses_journal_id', $reward->journal_id)->sharedLock()->exists()) throw new LogicException('COMMISSION_REWARD_INVALID');
            if (($row->state === 'accrued') !== ($amount !== '0') || bcdiv(bcmul(MoneyValue::minor($payload['net_minor']), (string) $snapshot['policy']['rate_bps'], 0), '10000', 0) !== $amount
                || bccomp($payload['net_minor'], $snapshot['price_minor'], 0) > 0) throw new LogicException('COMMISSION_REWARD_INVALID');
            if ($amount !== '0') {
                $journal = $this->ledger->inspectJournalLocked((int) $reward->journal_id);
                if ($journal['installation_id'] !== $row->installation_id || $journal['user_id'] !== (string) $row->sponsor_id) throw new LogicException('COMMISSION_REWARD_INVALID');
                foreach ($this->rewardJournal($row, $amount, $settled) as $field => $value) if ($journal[$field] !== $value) throw new LogicException('COMMISSION_REWARD_INVALID');
            }
        }
        return ['id' => $row->id, 'order_id' => (string) $row->order_id, 'buyer_id' => (string) $row->buyer_id, 'sponsor_id' => (string) $row->sponsor_id,
            'state' => $row->state, 'amount_minor' => $amount, 'currency' => 'CNY', 'rate_bps' => $snapshot['policy']['rate_bps'],
            'refund_policy' => self::REFUND_POLICY, 'hold_seconds' => $snapshot['policy']['hold_seconds'], 'created_at' => (int) $row->created_at, 'settled_at' => $settled];
    }
    private function rewardJournal(object $row, string $amount, int $at): array
    {
        return ['business_type' => 'commission_accrue', 'business_ref' => $row->id, 'idempotency_key' => 'commission.'.$row->id, 'currency' => 'CNY', 'occurred_at' => (string) $at,
            'postings' => [['owner_id' => '0', 'purpose' => 'commission_expense', 'side' => 'debit', 'amount_minor' => $amount],
                ['owner_id' => (string) $row->sponsor_id, 'purpose' => 'commission_available', 'side' => 'credit', 'amount_minor' => $amount]]];
    }
    private function payoutPost(array $installation, array $terms, string $kind, int $at): array
    {
        return $this->ledger->postLocked($installation, (int) $terms['user_id'], $this->payoutJournal($terms, $kind, $at));
    }
    private function payoutJournal(array $terms, string $kind, int $at): array
    {
        [$debit, $credit] = match ($kind) {
            'reserve' => ['commission_available', 'commission_reserved'],
            'approve' => ['commission_reserved', 'wallet_available'],
            'reject' => ['commission_reserved', 'commission_available'],
        };
        return ['business_type' => 'commission_'.$kind, 'business_ref' => $terms['id'], 'idempotency_key' => 'commission.'.$terms['id'].'.'.$kind,
            'currency' => 'CNY', 'occurred_at' => (string) $at, 'postings' => [
                ['owner_id' => $terms['user_id'], 'purpose' => $debit, 'side' => 'debit', 'amount_minor' => $terms['amount_minor']],
                ['owner_id' => $terms['user_id'], 'purpose' => $credit, 'side' => 'credit', 'amount_minor' => $terms['amount_minor']]]];
    }
    private function payoutTerms(object $row): array
    {
        $terms = json_decode($row->terms_json, true, 16, JSON_THROW_ON_ERROR);
        if (!hash_equals($row->terms_hash, MoneyValue::hash($terms)) || $terms['currency'] !== 'CNY' || $terms['destination'] !== 'native-wallet') throw new LogicException('COMMISSION_PAYOUT_INVALID');
        foreach (['id', 'installation_id', 'user_id', 'created_at'] as $field) if (($terms[$field] ?? null) !== (string) $row->$field) throw new LogicException('COMMISSION_PAYOUT_INVALID');
        MoneyValue::minor($terms['amount_minor'], true);
        return $terms;
    }
    private function payoutDto(object $row): array
    {
        $terms = $this->payoutTerms($row);
        return ['id' => $row->id, 'user_id' => (string) $row->user_id, 'state' => $row->state, 'row_version' => (string) $row->row_version,
            'amount_minor' => $terms['amount_minor'], 'currency' => 'CNY', 'destination' => 'native-wallet',
            'actor_id' => $row->actor_id === null ? null : (string) $row->actor_id, 'reason' => $row->reason,
            'created_at' => (int) $row->created_at, 'closed_at' => $row->closed_at === null ? null : (int) $row->closed_at];
    }
    private function verifyPayout(array $installation, object $row): array
    {
        $terms = $this->payoutTerms($row);
        if ($terms['installation_id'] !== $installation['installation_id'] || !in_array($row->state, ['requested', 'approved', 'rejected'], true)
            || (string) $row->row_version !== ($row->state === 'requested' ? '1' : '2')
            || ($row->state === 'requested') !== ($row->closed_at === null) || ($row->state === 'requested') !== ($row->decision_journal_id === null)
            || ($row->state === 'requested') !== ($row->actor_id === null) || ($row->state === 'requested') !== ($row->reason === null)) throw new LogicException('COMMISSION_PAYOUT_INVALID');
        $terminal = DB::table('tx_native_commission_payout_terminal')->where('payout_id', $row->id)->sharedLock()->first();
        if (($row->state === 'requested') !== ($terminal === null)) throw new LogicException('COMMISSION_PAYOUT_INVALID');
        if ($terminal !== null) {
            $payload = json_decode($terminal->payload_json, true, 16, JSON_THROW_ON_ERROR);
            if (!hash_equals($terminal->content_hash, MoneyValue::hash($payload)) || $payload !== $this->payoutTerminal($row)) throw new LogicException('COMMISSION_PAYOUT_INVALID');
        }
        foreach ($row->state === 'requested' ? ['reserve'] : ['reserve', $row->state === 'approved' ? 'approve' : 'reject'] as $kind) {
            $journalId = $kind === 'reserve' ? $row->reserve_journal_id : $row->decision_journal_id;
            $journal = $this->ledger->inspectJournalLocked((int) $journalId);
            if ($journal['installation_id'] !== $installation['installation_id'] || $journal['user_id'] !== $terms['user_id']
                || DB::table('tx_money_journal')->where('reverses_journal_id', $journalId)->sharedLock()->exists()) throw new LogicException('COMMISSION_PAYOUT_INVALID');
            foreach ($this->payoutJournal($terms, $kind, (int) ($kind === 'reserve' ? $row->created_at : $row->closed_at)) as $field => $value)
                if ($journal[$field] !== $value) throw new LogicException('COMMISSION_PAYOUT_INVALID');
        }
        return $terms;
    }
    private function payoutTerminal(object $row): array
    {
        $payload = [];
        foreach (['id', 'installation_id', 'user_id', 'state', 'row_version', 'reserve_journal_id', 'decision_journal_id', 'actor_id', 'reason', 'closed_at'] as $field)
            $payload[$field] = $row->$field === null ? null : (string) $row->$field;
        return $payload;
    }
    private function lockMoney(array $installation, array $users): void
    {
        $users = array_unique($users); sort($users, SORT_NUMERIC);
        foreach ($users as $userId) $this->ledger->lockAccountsLocked($installation, $userId, 'CNY', [
            ['owner_id' => '0', 'purpose' => 'commission_expense'], ['owner_id' => '0', 'purpose' => 'provider_clearing'],
            ['owner_id' => '0', 'purpose' => 'refund_liability'], ['owner_id' => '0', 'purpose' => 'sales_revenue'],
            ['owner_id' => (string) $userId, 'purpose' => 'commission_available'], ['owner_id' => (string) $userId, 'purpose' => 'commission_reserved'],
            ['owner_id' => (string) $userId, 'purpose' => 'wallet_available'], ['owner_id' => (string) $userId, 'purpose' => 'wallet_reserved']]);
    }
    private function owned(int $actorId, bool $admin, bool $write, array $users, \Closure $operation): mixed
    {
        if ($actorId < 1 || DB::transactionLevel() !== 0) throw new LogicException('COMMISSION_TRANSACTION_INVALID');
        return DB::transaction(function () use ($actorId, $admin, $write, $users, $operation): mixed {
            $installation = $write ? app(InstallationAuthority::class)->assertNativeMutation() : app(InstallationAuthority::class)->assertNativeRead();
            $users[] = $actorId; $users = array_unique($users); sort($users, SORT_NUMERIC);
            foreach ($users as $id) if (DB::table('tx_user')->where('id', $id)->lockForUpdate()->first() === null) throw new BillingError('BILLING_ACCOUNT_MISSING');
            $this->sessions->assertCurrentLocked($actorId, $admin ? ($write ? 'billing.commissions.manage' : 'billing.commissions.read') : null);
            return $operation($installation);
        }, 3);
    }
    private function event(array $installation, string $ref, string $action, ?int $actorId, array $result, int $now): void
    {
        $context = $this->invocation->current();
        $this->events->appendLocked(new DomainEvent(hash('sha256', $installation['installation_id'].':commission:'.$ref),
            $installation['installation_id'], 'billing.commission.changed.v1', 'commission:'.$ref, $result + ['action' => $action,
                'actor_id' => $actorId === null ? null : (string) $actorId], $now, $context?->correlationId ?? (string) Str::uuid()));
    }
    private static function decimal(mixed $value, bool $positive = false): void { if (!is_string($value) || MoneyValue::minor($value, $positive) !== $value) self::invalid(); }
    private static function invalid(): never { throw new \InvalidArgumentException('COMMISSION_INPUT_INVALID'); }
}
