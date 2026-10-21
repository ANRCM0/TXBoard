<?php

namespace App\Domains\Billing;

use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Subscription\Services\SubscriptionTermsTimeline;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Versioned future-purchase pricing and sponsor policy; historical grants stay frozen. */
final class NativeCustomerPolicies
{
    public const PERMISSION = 'billing.customer-policies.manage';

    public function read(int $actorId, int $userId): array
    {
        return $this->owned($actorId, $userId, false, fn (array $installation, User $target): array =>
            $this->snapshotLocked($installation['installation_id'], $userId) + ['editable' => !$target->is_admin && !$target->is_staff && $actorId !== $userId]);
    }

    public function update(int $actorId, int $userId, array $input, string $key): array
    {
        if (!Str::isUuid($key) || array_diff(array_keys($input), ['expected_policy_revision', 'changes', 'reason'])
            || !is_string($input['expected_policy_revision'] ?? null) || !preg_match('/\A[0-9a-f]{64}\z/D', $input['expected_policy_revision'])
            || !is_array($input['changes'] ?? null) || $input['changes'] === [] || array_is_list($input['changes'])
            || array_diff(array_keys($input['changes']), ['discount_bps', 'commission_mode', 'commission_rate_bps'])
            || !is_string($input['reason'] ?? null) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}\z/D', $input['reason'])) self::invalid();
        $this->validatePolicy($input['changes'], false);
        return $this->owned($actorId, $userId, true, function (array $installation, User $target) use ($actorId, $userId, $input, $key): array {
            if ($target->is_admin || $target->is_staff || $actorId === $userId) throw new BillingError('CUSTOMER_POLICY_TARGET_PROTECTED');
            $request = ['installation_id' => $installation['installation_id'], 'actor_id' => (string) $actorId, 'user_id' => (string) $userId, 'input' => $input];
            $keyHash = $this->hash([$installation['installation_id'], (string) $actorId, strtolower($key)]);
            $receipt = DB::table('tx_native_customer_policy_request')->where('key_hash', $keyHash)->lockForUpdate()->first();
            if ($receipt) {
                if (!hash_equals($receipt->request_hash, $this->hash($request))) throw new BillingError('CUSTOMER_POLICY_IDEMPOTENCY_CONFLICT');
                $result = $this->decode($receipt->result_json); $stored = $this->decode($receipt->request_json);
                if (!hash_equals($receipt->content_hash, $this->hash([$stored, $result])) || $stored !== $this->decode(SubscriptionTermsTimeline::json($request))
                    || $receipt->installation_id !== $installation['installation_id'] || (string) $receipt->actor_id !== (string) $actorId || (string) $receipt->user_id !== (string) $userId) self::corrupt();
                $this->verifyFrozen($installation['installation_id'], $userId, $result['policy']);
                return $result;
            }
            $old = $this->snapshotLocked($installation['installation_id'], $userId);
            if (!hash_equals($old['policy_revision'], $input['expected_policy_revision'])) throw new BillingError('CUSTOMER_POLICY_VERSION_CONFLICT');
            $policy = array_replace(array_intersect_key($old, array_flip(['discount_bps', 'commission_mode', 'commission_rate_bps'])), $input['changes']);
            $this->validatePolicy($policy, true);
            if ($policy === array_intersect_key($old, $policy)) throw new BillingError('CUSTOMER_POLICY_NO_CHANGE');
            $version = (int) $old['policy_version'] + 1; if ($version < 1 || $version >= PHP_INT_MAX) self::corrupt();
            $payload = ['installation_id' => $installation['installation_id'], 'user_id' => (string) $userId, 'version' => (string) $version,
                'actor_id' => (string) $actorId, 'reason' => $input['reason'], 'previous_revision' => $old['policy_revision'], 'policy' => $policy, 'created_at' => (string) time()];
            DB::table('tx_native_customer_policy_revision')->insert(['installation_id' => $installation['installation_id'], 'user_id' => $userId, 'version' => $version,
                'actor_id' => $actorId, 'payload_json' => SubscriptionTermsTimeline::json($payload), 'content_hash' => $this->hash($payload), 'created_at' => $payload['created_at']]);
            DB::table('tx_native_customer_policy_head')->updateOrInsert(['user_id' => $userId], ['installation_id' => $installation['installation_id'], 'version' => $version]);
            $result = ['user_id' => (string) $userId, 'policy' => $this->snapshotLocked($installation['installation_id'], $userId), 'actor_id' => (string) $actorId,
                'reason' => $input['reason'], 'occurred_at' => $payload['created_at']];
            $result = $this->decode(SubscriptionTermsTimeline::json($result));
            DB::table('tx_native_customer_policy_request')->insert(['key_hash' => $keyHash, 'installation_id' => $installation['installation_id'], 'actor_id' => $actorId, 'user_id' => $userId,
                'request_hash' => $this->hash($request), 'request_json' => SubscriptionTermsTimeline::json($request), 'result_json' => SubscriptionTermsTimeline::json($result), 'content_hash' => $this->hash([$request, $result])]);
            return $result;
        });
    }

    public function history(int $actorId, int $userId, ?string $before = null, int $limit = 25): array
    {
        if ($before !== null) MoneyValue::minor($before, true);
        if ($limit < 1 || $limit > 100) self::invalid();
        return $this->owned($actorId, $userId, false, function (array $installation) use ($userId, $before, $limit): array {
            $query = DB::table('tx_native_customer_policy_revision')->where('user_id', $userId);
            if ($before !== null) $query->where('version', '<', $before);
            $rows = $query->orderByDesc('version')->limit($limit + 1)->sharedLock()->get(); $items = [];
            foreach ($rows->take($limit) as $row) $items[] = $this->verifyRevision($installation['installation_id'], $userId, $row);
            return ['items' => $items, 'next_cursor' => $rows->count() > $limit ? (string) $rows[$limit - 1]->version : null];
        });
    }

    /** Caller owns the target account, shared by policy changes and purchase capture. */
    public function snapshotLocked(string $installation, int $userId): array
    {
        $this->transaction(); $this->capability();
        $head = DB::table('tx_native_customer_policy_head')->where('user_id', $userId)->sharedLock()->first();
        $row = DB::table('tx_native_customer_policy_revision')->where('user_id', $userId)->orderByDesc('version')->sharedLock()->first();
        if ($head === null && $row === null) return $this->defaultPolicy($installation, $userId);
        if (!$head || !$row || $head->installation_id !== $installation || (string) $head->version !== (string) $row->version) self::corrupt();
        $payload = $this->verifyRevision($installation, $userId, $row);
        return $payload['policy'] + ['user_id' => (string) $userId, 'policy_version' => (string) $row->version, 'policy_revision' => $row->content_hash];
    }

    public function verifyFrozen(string $installation, int $userId, array $policy): void
    {
        $this->validatePolicy(array_intersect_key($policy, array_flip(['discount_bps', 'commission_mode', 'commission_rate_bps'])), true);
        if (($policy['user_id'] ?? null) !== (string) $userId || !is_string($policy['policy_version'] ?? null)) self::corrupt();
        if ($policy['policy_version'] === '0') { if (SubscriptionTermsTimeline::json($policy) !== SubscriptionTermsTimeline::json($this->defaultPolicy($installation, $userId))) self::corrupt(); return; }
        $row = DB::table('tx_native_customer_policy_revision')->where('user_id', $userId)->where('version', $policy['policy_version'])->sharedLock()->first();
        if (!$row) self::corrupt(); $payload = $this->verifyRevision($installation, $userId, $row);
        $expected = $payload['policy'] + ['user_id' => (string) $userId, 'policy_version' => (string) $row->version, 'policy_revision' => $row->content_hash];
        if (SubscriptionTermsTimeline::json($expected) !== SubscriptionTermsTimeline::json($policy)) self::corrupt();
    }

    public function quotePriceLocked(array $installation, int $userId, object $offer, int $now): array
    {
        $policy = $this->snapshotLocked($installation['installation_id'], $userId); $list = MoneyValue::minor((string) $offer->price_minor);
        $discount = bcdiv(bcmul($list, (string) $policy['discount_bps'], 0), '10000', 0);
        return ['schema' => 'native-customer-price/1', 'installation_id' => $installation['installation_id'], 'user_id' => (string) $userId,
            'policy' => $policy, 'offer_id' => (string) $offer->id, 'currency' => $offer->currency, 'list_minor' => $list,
            'discount_minor' => $discount, 'discounted_minor' => bcsub($list, $discount, 0), 'quoted_at' => (string) $now];
    }

    public function persistPriceLocked(Order $order, array $quote, ?array $coupon, ?array $credit): void
    {
        $this->transaction(); if ($quote['policy']['policy_version'] === '0') return;
        $quote['order_id'] = (string) $order->id; $quote['coupon_net_minor'] = $coupon['net_minor'] ?? null;
        $quote['coupon_discount_minor'] = $coupon['discount_minor'] ?? '0'; $quote['upgrade_list_minor'] = $credit['list_minor'] ?? null;
        $quote['net_minor'] = (string) $order->total_amount;
        DB::table('tx_native_customer_price')->insert(['order_id' => $order->id, 'installation_id' => $quote['installation_id'], 'user_id' => $order->user_id,
            'policy_version' => $quote['policy']['policy_version'], 'payload_json' => SubscriptionTermsTimeline::json($quote), 'content_hash' => $this->hash($quote)]);
    }

    /** Independent immutable price verification; it never reads the current policy head. */
    public function priceBinding(Order $order, object $offer): ?array
    {
        if (!Schema::hasTable('tx_native_customer_price')) return null;
        $row = DB::table('tx_native_customer_price')->where('order_id', $order->id)->sharedLock()->first(); if (!$row) return null;
        $quote = $this->decode($row->payload_json);
        if (!hash_equals($row->content_hash, $this->hash($quote)) || ($quote['schema'] ?? null) !== 'native-customer-price/1'
            || $quote['installation_id'] !== $row->installation_id || $quote['user_id'] !== (string) $row->user_id || $quote['user_id'] !== (string) $order->user_id
            || $quote['order_id'] !== (string) $order->id || $quote['policy']['policy_version'] !== (string) $row->policy_version
            || $quote['offer_id'] !== (string) $offer->id || $quote['currency'] !== $offer->currency || $quote['list_minor'] !== (string) $offer->price_minor
            || $quote['net_minor'] !== (string) $order->total_amount) self::corrupt();
        $this->verifyFrozen($row->installation_id, (int) $order->user_id, $quote['policy']);
        $discount = bcdiv(bcmul(MoneyValue::minor($quote['list_minor']), (string) $quote['policy']['discount_bps'], 0), '10000', 0);
        if ($discount !== $quote['discount_minor'] || bcsub($quote['list_minor'], $discount, 0) !== $quote['discounted_minor']) self::corrupt();
        $coupon = app(NativeCouponWorkflow::class)->binding((int) $order->id, (int) $order->user_id, (int) $offer->id, $offer->currency,
            $quote['list_minor'], $quote['coupon_net_minor'] ?? $quote['list_minor']);
        if (($coupon['discount_minor'] ?? '0') !== $quote['coupon_discount_minor']) self::corrupt();
        $credit = null;
        if ((int) $order->type === Order::TYPE_UPGRADE) {
            if ($coupon !== null || $quote['upgrade_list_minor'] !== $quote['discounted_minor']) self::corrupt();
            $discountedOffer = clone $offer; $discountedOffer->price_minor = $quote['discounted_minor'];
            $credit = app(NativeUpgradeCredits::class)->binding($order, $discountedOffer);
        } else {
            $net = bcsub($quote['discounted_minor'], $quote['coupon_discount_minor'], 0); if (bccomp($net, '0', 0) < 0) $net = '0';
            if ($quote['upgrade_list_minor'] !== null || $net !== $quote['net_minor']) self::corrupt();
        }
        return ['binding' => ['policy_version' => $quote['policy']['policy_version'], 'policy_revision' => $quote['policy']['policy_revision'],
            'content_hash' => $row->content_hash, 'discount_bps' => $quote['policy']['discount_bps'], 'discount_minor' => $discount,
            'composition' => $credit !== null ? 'personal-before-upgrade-credit/v1' : 'capped-list-discount-sum/v1'], 'coupon' => $coupon, 'credit' => $credit];
    }

    /** Buyer lock serializes accepted payment allocations; refunds never transfer first-paid eligibility. */
    public function claimFirstPaidLocked(array $installation, int $userId, bool $create = true): ?array
    {
        $this->transaction(); $this->capability();
        $allocation = DB::table('tx_order_payment')->where('installation_id', $installation['installation_id'])->where('user_id', $userId)
            ->where('amount_minor', '>', 0)->orderBy('id')->sharedLock()->first();
        $claimQuery = DB::table('tx_native_first_paid_claim')->where('user_id', $userId);
        $row = ($create ? $claimQuery->lockForUpdate() : $claimQuery->sharedLock())->first();
        if (!$allocation) { if ($row) self::corrupt(); return null; }
        $paymentEvidence = [];
        foreach (['installation_id', 'user_id', 'order_id', 'settlement_id', 'receipt_id', 'amount_minor', 'currency'] as $field) $paymentEvidence[$field] = $allocation->$field === null ? null : (string) $allocation->$field;
        if ($allocation->wallet_reservation_id !== null) $paymentEvidence['wallet_reservation_id'] = (string) $allocation->wallet_reservation_id;
        if (!hash_equals($allocation->content_hash, MoneyValue::hash($paymentEvidence))) self::corrupt();
        $payload = ['installation_id' => $installation['installation_id'], 'user_id' => (string) $userId, 'order_id' => (string) $allocation->order_id,
            'order_payment_id' => (string) $allocation->id, 'payment_hash' => $allocation->content_hash];
        if (!$row) {
            if (!$create) self::corrupt();
            DB::table('tx_native_first_paid_claim')->insert(['user_id' => $userId, 'installation_id' => $installation['installation_id'],
                'order_id' => $allocation->order_id, 'order_payment_id' => $allocation->id, 'payload_json' => SubscriptionTermsTimeline::json($payload), 'content_hash' => $this->hash($payload)]);
        } elseif ($row->installation_id !== $installation['installation_id'] || (string) $row->order_id !== $payload['order_id']
            || (string) $row->order_payment_id !== $payload['order_payment_id'] || !hash_equals($row->content_hash, $this->hash($payload))
            || $this->decode($row->payload_json) !== $this->decode(SubscriptionTermsTimeline::json($payload))) self::corrupt();
        return $payload;
    }

    private function verifyRevision(string $installation, int $userId, object $row): array
    {
        $payload = $this->decode($row->payload_json);
        if ($row->installation_id !== $installation || (string) $row->user_id !== (string) $userId || !hash_equals($row->content_hash, $this->hash($payload))
            || ($payload['installation_id'] ?? null) !== $installation || ($payload['user_id'] ?? null) !== (string) $userId
            || ($payload['version'] ?? null) !== (string) $row->version || ($payload['actor_id'] ?? null) !== (string) $row->actor_id
            || ($payload['created_at'] ?? null) !== (string) $row->created_at) self::corrupt();
        $this->validatePolicy($payload['policy'] ?? [], true); return $payload;
    }
    private function defaultPolicy(string $installation, int $userId): array
    {
        return ['discount_bps' => 0, 'commission_mode' => 'inherit', 'commission_rate_bps' => null,
            'user_id' => (string) $userId, 'policy_version' => '0', 'policy_revision' => $this->hash([$installation, (string) $userId, 'default-policy/v1'])];
    }
    private function validatePolicy(array $policy, bool $complete): void
    {
        if ($complete && (count($policy) !== 3 || !array_key_exists('discount_bps', $policy) || !array_key_exists('commission_mode', $policy) || !array_key_exists('commission_rate_bps', $policy))) self::invalid();
        foreach (['discount_bps', 'commission_rate_bps'] as $field) if (array_key_exists($field, $policy)
            && !($field === 'commission_rate_bps' && $policy[$field] === null) && (!is_int($policy[$field]) || $policy[$field] < 0 || $policy[$field] > 10000)) self::invalid();
        if (array_key_exists('commission_mode', $policy) && !in_array($policy['commission_mode'], ['inherit', 'recurring', 'first_paid'], true)) self::invalid();
    }
    private function owned(int $actorId, int $userId, bool $write, \Closure $operation): mixed
    {
        if ($actorId < 1 || $userId < 1 || DB::transactionLevel() !== 0) self::invalid();
        return DB::transaction(function () use ($actorId, $userId, $write, $operation): mixed {
            $installation = $write ? app(InstallationAuthority::class)->assertNativeMutation() : app(InstallationAuthority::class)->assertNativeRead();
            $users = User::query()->whereIn('id', [$actorId, $userId])->orderBy('id')->lockForUpdate()->get();
            app(CurrentSessionAuthorization::class)->assertCurrentLocked($actorId, self::PERMISSION); $this->capability();
            $target = $users->firstWhere('id', $userId); if (!$target) throw new BillingError('CUSTOMER_POLICY_ACCOUNT_MISSING');
            return $operation($installation, $target);
        }, 3);
    }
    private function capability(): void { if (!Schema::hasTable('tx_native_customer_policy_head') || !Schema::hasTable('tx_native_customer_price') || !Schema::hasTable('tx_native_first_paid_claim')) throw new BillingError('CUSTOMER_POLICY_CAPABILITY_UNAVAILABLE'); }
    private function transaction(): void { if (DB::transactionLevel() < 1) throw new BillingError('CUSTOMER_POLICY_TRANSACTION_REQUIRED'); }
    private function hash(array $value): string { return hash_hmac('sha256', SubscriptionTermsTimeline::json($value), (string) config('app.key')); }
    private function decode(string $value): array { try { $data = json_decode($value, true, 64, JSON_THROW_ON_ERROR); } catch (\JsonException) { self::corrupt(); } if (!is_array($data) || array_is_list($data)) self::corrupt(); return $data; }
    private static function invalid(): never { throw new BillingError('CUSTOMER_POLICY_INPUT_INVALID'); }
    private static function corrupt(): never { throw new BillingError('CUSTOMER_POLICY_EVIDENCE_INVALID'); }
}
