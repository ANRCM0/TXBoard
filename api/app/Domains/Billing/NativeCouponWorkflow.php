<?php

namespace App\Domains\Billing;

use App\Core\Kernel\Execution\CurrentInvocation;
use App\Domains\Billing\Contracts\CouponAdministration;
use App\Domains\Billing\Contracts\OrderPage;
use App\Domains\Billing\Internal\LockedCouponDiscounts;
use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\Installation\InstallationAuthority;
use App\Domains\Notifications\Internal\EventJournal;
use App\Extensions\Contracts\DomainEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/** Noncash price discounts. Coupon usage is permanent at order creation, including cancellation/refund. */
final class NativeCouponWorkflow implements CouponAdministration, LockedCouponDiscounts
{
    public const SCHEMA = 'native-coupon-discount/1';
    public const USAGE_POLICY = 'retained-on-order-create/v1';

    public function __construct(private readonly CurrentSessionAuthorization $sessions,
        private readonly EventJournal $events, private readonly CurrentInvocation $invocation) {}

    public function listing(int $actorId, int $page, int $perPage): OrderPage
    {
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 50) self::invalid();
        return $this->owned($actorId, false, function () use ($page, $perPage): OrderPage {
            $result = DB::table('tx_native_coupon')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
            return new OrderPage(array_map($this->dto(...), $result->items()),
                ['page' => $result->currentPage(), 'per_page' => $result->perPage(), 'total' => $result->total(), 'last_page' => $result->lastPage()]);
        });
    }

    public function find(int $actorId, string $id): ?array
    {
        self::id($id);
        return $this->owned($actorId, false, function () use ($id): ?array {
            $row = DB::table('tx_native_coupon')->where('id', $id)->sharedLock()->first();
            return $row === null ? null : $this->dto($row);
        });
    }

    public function execute(int $actorId, string $action, array $input, string $key): array
    {
        if (!in_array($action, ['create', 'disable'], true) || !\Illuminate\Support\Str::isUuid($key)) self::invalid();
        $policy = $action === 'create' ? self::policy($input) : null;
        if ($action !== 'create') {
            if (count($input) !== 2 || !isset($input['coupon_id'], $input['expected_version'])) self::invalid();
            self::id($input['coupon_id']); self::id($input['expected_version']);
        }
        return $this->owned($actorId, true, function (array $installation) use ($actorId, $action, $input, $key, &$policy): array {
            $requestHash = MoneyValue::hash($input);
            $keyHash = hash('sha256', $installation['installation_id'].':'.$actorId.':coupon.'.$action.':'.strtolower($key));
            $old = DB::table('tx_native_coupon_request')->where('key_hash', $keyHash)->lockForUpdate()->first();
            if ($old !== null) {
                if ($old->installation_id !== $installation['installation_id'] || (int) $old->actor_id !== $actorId
                    || $old->action !== $action) throw new LogicException('COUPON_RECEIPT_IDENTITY_INVALID');
                if (!hash_equals($old->input_hash, $requestHash)) throw new BillingError('COUPON_IDEMPOTENCY_CONFLICT');
                if (!hash_equals($old->content_hash, hash('sha256', $old->input_hash.':'.$old->response_json))) throw new LogicException('COUPON_RECEIPT_INVALID');
                return json_decode($old->response_json, true, 16, JSON_THROW_ON_ERROR);
            }
            $now = time();
            if ($action === 'create') {
                if (DB::table('tx_native_coupon')->where('code_hash', hash('sha256', $policy['code']))->lockForUpdate()->first() !== null) throw new BillingError('COUPON_CODE_CONFLICT');
                $id = DB::table('tx_native_coupon')->insertGetId(['code_hash' => hash('sha256', $policy['code']),
                    'policy_json' => json_encode($policy, JSON_THROW_ON_ERROR), 'policy_hash' => MoneyValue::hash($policy),
                    'enabled' => true, 'row_version' => 1, 'used_total' => 0, 'created_at' => $now, 'updated_at' => $now]);
                $result = ['id' => (string) $id, 'row_version' => '1', 'enabled' => true];
            } else {
                $row = DB::table('tx_native_coupon')->where('id', $input['coupon_id'])->lockForUpdate()->first();
                if ($row === null) abort(404);
                $this->verifiedPolicy($row);
                if ((string) $row->row_version !== $input['expected_version']) throw new BillingError('COUPON_VERSION_CONFLICT');
                $version = self::version($row->row_version);
                DB::table('tx_native_coupon')->where('id', $row->id)->update(['enabled' => false, 'row_version' => $version, 'updated_at' => $now]);
                $result = ['id' => (string) $row->id, 'row_version' => (string) $version, 'enabled' => false];
            }
            $context = $this->invocation->current();
            if ($context === null) throw new LogicException('COUPON_INVOCATION_REQUIRED');
            $eventId = hash('sha256', $installation['installation_id'].':coupon:'.$result['id'].':'.$result['row_version']);
            $this->events->appendLocked(new DomainEvent($eventId, $installation['installation_id'], 'billing.coupon.changed.v1',
                'coupon:'.$result['id'], $result + ['actor_id' => (string) $actorId, 'action' => $action, 'input_hash' => $requestHash], $now, $context->correlationId));
            $json = json_encode($result, JSON_THROW_ON_ERROR);
            DB::table('tx_native_coupon_request')->insert(['key_hash' => $keyHash, 'input_hash' => $requestHash,
                'installation_id' => $installation['installation_id'], 'actor_id' => $actorId, 'action' => $action,
                'response_json' => $json, 'content_hash' => hash('sha256', $requestHash.':'.$json), 'created_at' => $now]);
            return $result;
        });
    }

    public function quoteLocked(int $userId, int $offerId, string $currency, string $listMinor, string $code, int $now, string $installationId): array
    {
        $this->transaction();
        $this->sessions->assertCurrentLocked($userId);
        $code = self::code($code);
        $row = DB::table('tx_native_coupon')->where('code_hash', hash('sha256', $code))->lockForUpdate()->first();
        if ($row === null) throw new BillingError('COUPON_UNAVAILABLE');
        $policy = $this->verifiedPolicy($row);
        if (!$row->enabled || $currency !== 'CNY' || $listMinor === '0'
            || $policy['starts_at'] !== null && $now < $policy['starts_at']
            || $policy['expires_at'] !== null && $now >= $policy['expires_at']
            || $policy['offer_ids'] !== [] && !in_array((string) $offerId, $policy['offer_ids'], true)
            || bccomp(MoneyValue::minor($listMinor), $policy['minimum_minor'], 0) < 0) throw new BillingError('COUPON_UNAVAILABLE');
        $usage = DB::table('tx_native_coupon_user')->where('coupon_id', $row->id)->where('user_id', $userId)->lockForUpdate()->first();
        // Counters are projections of immutable sequence heads. A lost/decreased counter
        // must fail before it can grant another use, even under MySQL repeatable read.
        $head = DB::table('tx_native_coupon_application')->where('coupon_id', $row->id)->orderByDesc('usage_sequence')->lockForUpdate()->first();
        $userHead = DB::table('tx_native_coupon_application')->where('coupon_id', $row->id)->where('user_id', $userId)->orderByDesc('user_sequence')->lockForUpdate()->first();
        foreach ([$head, $userHead] as $evidence) {
            if ($evidence !== null) {
                $frozen = json_decode($evidence->quote_json, true, 32, JSON_THROW_ON_ERROR);
                if (!hash_equals($evidence->content_hash, MoneyValue::hash($frozen))
                    || $evidence->installation_id !== $installationId) throw new LogicException('COUPON_USAGE_EVIDENCE_INVALID');
                foreach (['order_id', 'user_id', 'coupon_id', 'usage_sequence', 'user_sequence'] as $field) {
                    if (($frozen[$field] ?? null) !== (string) $evidence->$field) throw new LogicException('COUPON_USAGE_EVIDENCE_INVALID');
                }
            }
        }
        if ((int) ($head->usage_sequence ?? 0) !== (int) $row->used_total
            || (int) ($userHead->user_sequence ?? 0) !== (int) ($usage->used_count ?? 0)) throw new LogicException('COUPON_USAGE_COUNTER_INVALID');
        if ((int) $row->used_total >= $policy['total_limit'] || (int) ($usage->used_count ?? 0) >= $policy['user_limit']) throw new BillingError('COUPON_USAGE_EXHAUSTED');
        $discount = $policy['discount_kind'] === 'fixed' ? $policy['value'] : bcdiv(bcmul($listMinor, $policy['value'], 0), '10000', 0);
        if (bccomp($discount, $listMinor, 0) > 0) $discount = $listMinor;
        if ($discount === '0') throw new BillingError('COUPON_NO_DISCOUNT');
        return ['schema' => self::SCHEMA, 'installation_id' => $installationId, 'user_id' => (string) $userId,
            'coupon_id' => (string) $row->id, 'coupon_version' => (string) $row->row_version,
            'policy' => $policy, 'policy_hash' => $row->policy_hash, 'offer_id' => (string) $offerId,
            'currency' => $currency, 'list_minor' => $listMinor, 'discount_minor' => $discount,
            'net_minor' => bcsub($listMinor, $discount, 0), 'quoted_at' => (string) $now, 'usage_policy' => self::USAGE_POLICY,
            'usage_sequence' => (string) ((int) $row->used_total + 1),
            'user_sequence' => (string) ((int) ($usage->used_count ?? 0) + 1)];
    }

    public function persistLocked(int $orderId, array $quote): void
    {
        $this->transaction();
        $current = $this->quoteLocked((int) $quote['user_id'], (int) $quote['offer_id'], $quote['currency'],
            $quote['list_minor'], $quote['policy']['code'], (int) $quote['quoted_at'], $quote['installation_id']);
        if (!hash_equals(MoneyValue::hash($current), MoneyValue::hash($quote))) throw new LogicException('COUPON_QUOTE_CHANGED');
        $quote['order_id'] = (string) $orderId;
        DB::table('tx_native_coupon_application')->insert(['order_id' => $orderId, 'user_id' => (int) $quote['user_id'],
            'coupon_id' => (int) $quote['coupon_id'], 'installation_id' => $quote['installation_id'],
            'usage_sequence' => (int) $quote['usage_sequence'], 'user_sequence' => (int) $quote['user_sequence'],
            'quote_json' => json_encode($quote, JSON_THROW_ON_ERROR), 'content_hash' => MoneyValue::hash($quote), 'created_at' => (int) $quote['quoted_at']]);
        DB::table('tx_native_coupon')->where('id', $quote['coupon_id'])->increment('used_total');
        $usage = DB::table('tx_native_coupon_user')->where('coupon_id', $quote['coupon_id'])->where('user_id', $quote['user_id']);
        if ($usage->lockForUpdate()->first() === null) $usage->insert(['coupon_id' => $quote['coupon_id'], 'user_id' => $quote['user_id'], 'used_count' => 1]);
        else $usage->increment('used_count');
    }

    /** Read immutable evidence only. Disabling/expiring the campaign cannot rewrite an existing purchase. */
    public function binding(int $orderId, int $userId, int $offerId, string $currency, string $listMinor, string $netMinor): ?array
    {
        $row = DB::table('tx_native_coupon_application')->useWritePdo()->where('order_id', $orderId)->sharedLock()->first();
        if ($row === null) return null;
        $quote = json_decode($row->quote_json, true, 32, JSON_THROW_ON_ERROR);
        if (!hash_equals($row->content_hash, MoneyValue::hash($quote)) || ($quote['schema'] ?? null) !== self::SCHEMA
            || ($quote['usage_policy'] ?? null) !== self::USAGE_POLICY || $quote['order_id'] !== (string) $orderId
            || $quote['user_id'] !== (string) $userId || $quote['offer_id'] !== (string) $offerId
            || $quote['installation_id'] !== $row->installation_id || $quote['coupon_id'] !== (string) $row->coupon_id
            || $quote['usage_sequence'] !== (string) $row->usage_sequence || $quote['user_sequence'] !== (string) $row->user_sequence
            || (string) $row->user_id !== (string) $userId || $quote['currency'] !== $currency || $currency !== 'CNY'
            || $quote['list_minor'] !== $listMinor || $quote['net_minor'] !== $netMinor
            || bcadd(MoneyValue::minor($quote['discount_minor'], true), MoneyValue::minor($netMinor), 0) !== $listMinor
            || !hash_equals($quote['policy_hash'], MoneyValue::hash(self::policy($quote['policy'])))) throw new LogicException('COUPON_PURCHASE_EVIDENCE_INVALID');
        $policy = $quote['policy'];
        $discount = $policy['discount_kind'] === 'fixed' ? $policy['value'] : bcdiv(bcmul($listMinor, $policy['value'], 0), '10000', 0);
        $discount = bccomp($discount, $listMinor, 0) > 0 ? $listMinor : $discount;
        if ($discount !== $quote['discount_minor']) throw new LogicException('COUPON_PURCHASE_EVIDENCE_INVALID');
        return ['coupon_id' => $quote['coupon_id'], 'coupon_version' => $quote['coupon_version'],
            'content_hash' => $row->content_hash, 'discount_minor' => $discount, 'usage_policy' => self::USAGE_POLICY];
    }

    private function owned(int $actorId, bool $write, \Closure $action): mixed
    {
        if (DB::transactionLevel() !== 0) throw new LogicException('COUPON_OUTER_TRANSACTION_UNSUPPORTED');
        return DB::transaction(function () use ($actorId, $write, $action): mixed {
            $installation = $write ? app(InstallationAuthority::class)->assertNativeMutation() : app(InstallationAuthority::class)->assertNativeRead();
            $this->sessions->assertCurrentLocked($actorId, $write ? 'billing.coupons.manage' : 'billing.coupons.read');
            return $action($installation);
        }, 3);
    }

    private function dto(object $row): array
    {
        return $this->verifiedPolicy($row) + ['id' => (string) $row->id, 'enabled' => (bool) $row->enabled,
            'row_version' => (string) $row->row_version, 'used_total' => (int) $row->used_total, 'usage_policy' => self::USAGE_POLICY,
            'created_at' => (int) $row->created_at, 'updated_at' => (int) $row->updated_at];
    }

    private function verifiedPolicy(object $row): array
    {
        $policy = self::policy(json_decode($row->policy_json, true, 16, JSON_THROW_ON_ERROR));
        self::id((string) $row->row_version);
        if (!hash_equals($row->policy_hash, MoneyValue::hash($policy)) || !hash_equals($row->code_hash, hash('sha256', $policy['code']))
            || (int) $row->used_total < 0 || (int) $row->used_total > $policy['total_limit']) throw new LogicException('COUPON_POLICY_INVALID');
        return $policy;
    }

    private static function policy(array $input): array
    {
        $fields = ['code', 'name', 'currency', 'discount_kind', 'value', 'minimum_minor', 'total_limit', 'user_limit', 'starts_at', 'expires_at', 'offer_ids'];
        if (count($input) !== count($fields) || array_diff($fields, array_keys($input))) self::invalid();
        if (self::code($input['code']) !== $input['code'] || !is_string($input['name']) || trim($input['name']) === ''
            || preg_match('//u', $input['name']) !== 1 || mb_strlen($input['name']) > 120 || $input['currency'] !== 'CNY'
            || !in_array($input['discount_kind'], ['fixed', 'basis_points'], true)) self::invalid();
        MoneyValue::minor($input['value'], true); MoneyValue::minor($input['minimum_minor']);
        if (!is_string($input['value']) || !is_string($input['minimum_minor']) || bccomp($input['value'], $input['discount_kind'] === 'fixed' ? '2147483647' : '10000', 0) > 0) self::invalid();
        foreach (['total_limit', 'user_limit'] as $field) if (!is_int($input[$field]) || $input[$field] < 1 || $input[$field] > 1000000000) self::invalid();
        if ($input['user_limit'] > $input['total_limit']) self::invalid();
        foreach (['starts_at', 'expires_at'] as $field) if ($input[$field] !== null && (!is_int($input[$field]) || $input[$field] < 0 || $input[$field] > 253402300799)) self::invalid();
        if ($input['starts_at'] !== null && $input['expires_at'] !== null && $input['expires_at'] <= $input['starts_at']) self::invalid();
        if (!is_array($input['offer_ids']) || !array_is_list($input['offer_ids']) || count($input['offer_ids']) > 256 || count(array_unique($input['offer_ids'], SORT_STRING)) !== count($input['offer_ids'])) self::invalid();
        foreach ($input['offer_ids'] as $id) self::id($id);
        return $input;
    }

    public static function code(mixed $code): string
    {
        if (!is_string($code) || preg_match('/\A[A-Z0-9][A-Z0-9_-]{7,63}\z/Di', $code) !== 1) self::invalid();
        return strtoupper($code);
    }
    private static function id(mixed $value): void
    {
        if (!is_string($value) || MoneyValue::minor($value, true) !== $value) self::invalid();
    }
    private static function version(mixed $value): int
    {
        self::id((string) $value);
        if ((string) $value === MoneyValue::MAX) throw new LogicException('COUPON_VERSION_EXHAUSTED');
        return (int) $value + 1;
    }
    private function transaction(): void { if (DB::transactionLevel() < 1) throw new LogicException('COUPON_TRANSACTION_REQUIRED'); }
    private static function invalid(): never { throw new InvalidArgumentException('COUPON_INPUT_INVALID'); }
}
