<?php

namespace App\Services\Subscription;

use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Bounded, resumable evidence capture on a quiesced isolated copy. Legacy writers remain authoritative. */
final class CommerceShadowService
{
    public const VERSION = 'commerce-shadow/v1';
    private const CURRENCIES = ['USD', 'CNY', 'EUR', 'GBP', 'HKD', 'SGD', 'CAD', 'AUD', 'NZD', 'TWD'];

    public function __construct(private CommerceShadowLedger $ledger) {}

    public function run(string $runKey, int $limit, array $context, ?string $retryType = null, ?string $retryId = null): array
    {
        $this->guard($runKey, $limit);
        $context = $this->context($context);
        if (($retryType === null) !== ($retryId === null) || ($retryType !== null && !in_array($retryType, ['user', 'order'], true))) {
            throw new InvalidArgumentException('INVALID_COMMERCE_RETRY');
        }
        if ($retryId !== null) {
            $retryId = CommerceShadowCodec::integer($retryId, positive: true);
        }
        return DB::transaction(function () use ($runKey, $limit, $context, $retryType, $retryId) {
            $now = time();
            DB::table('tx_commerce_shadow_checkpoint')->insertOrIgnore([
                'run_key' => $runKey, 'phase' => 'processing', 'cursor_user_id' => 0, 'cursor_order_id' => 0,
                'high_water_user_id' => DB::table('tx_user')->max('id') ?? 0,
                'high_water_order_id' => DB::table('tx_order')->max('id') ?? 0, 'processed_count' => 0,
                'details' => CommerceShadowCodec::json(['context' => $context, 'started_at' => $now]),
                'row_version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $checkpoint = DB::table('tx_commerce_shadow_checkpoint')->where('run_key', $runKey)->lockForUpdate()->first();
            $frozen = json_decode($checkpoint->details, true, flags: JSON_THROW_ON_ERROR)['context'];
            if (!hash_equals(CommerceShadowCodec::hash($context), CommerceShadowCodec::hash($frozen))) {
                throw new RuntimeException('COMMERCE_CONTEXT_CHANGED');
            }
            $work = [];
            if ($retryType !== null) {
                if ((int) $retryId > (int) $checkpoint->{'high_water_'.$retryType.'_id'}) {
                    throw new RuntimeException('COMMERCE_RETRY_OUTSIDE_HIGH_WATER');
                }
                $work[] = [$retryType, $retryId];
            } else {
                foreach (['user', 'order'] as $type) {
                    $remaining = $limit - count($work);
                    if ($remaining < 1) {
                        break;
                    }
                    $ids = DB::table('tx_'.$type)->where('id', '>', $checkpoint->{'cursor_'.$type.'_id'})
                        ->where('id', '<=', $checkpoint->{'high_water_'.$type.'_id'})->orderBy('id')->limit($remaining)->pluck('id');
                    foreach ($ids as $id) {
                        $work[] = [$type, CommerceShadowCodec::integer($id, positive: true)];
                    }
                }
            }
            $cursors = ['user' => (string) $checkpoint->cursor_user_id, 'order' => (string) $checkpoint->cursor_order_id];
            $results = [];
            foreach ($work as [$type, $id]) {
                try {
                    $results[] = DB::transaction(fn () => $type === 'user'
                        ? $this->captureUser($id, $runKey, $context, $now)
                        : $this->captureOrder($id, $runKey, $context, $now));
                    DB::table('tx_commerce_shadow_exception')->where(['aggregate_type' => $type, 'legacy_id' => $id, 'currency' => $context['currency']])
                        ->update(['state' => 'resolved', 'updated_at' => $now]);
                } catch (Throwable $error) {
                    $key = ['aggregate_type' => $type, 'legacy_id' => $id, 'currency' => $context['currency']];
                    $code = $this->errorCode($error);
                    DB::table('tx_commerce_shadow_exception')->insertOrIgnore($key + [
                        'run_key' => $runKey, 'state' => 'unresolved', 'error_code' => $code, 'attempts' => 0,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $previous = DB::table('tx_commerce_shadow_exception')->where($key)->lockForUpdate()->first();
                    DB::table('tx_commerce_shadow_exception')->where($key)->update([
                        'run_key' => $runKey, 'state' => 'unresolved', 'error_code' => $code,
                        'attempts' => CommerceShadowCodec::add((string) $previous->attempts, '1'), 'updated_at' => $now,
                    ]);
                    $results[] = ['aggregate_type' => $type, 'legacy_id' => $id, 'status' => 'exception', 'error_code' => $code];
                }
                if ($retryType === null) {
                    $cursors[$type] = $id;
                }
            }
            $more = false;
            foreach (['user', 'order'] as $type) {
                $more = $more || DB::table('tx_'.$type)->where('id', '>', $cursors[$type])
                    ->where('id', '<=', $checkpoint->{'high_water_'.$type.'_id'})->exists();
            }
            $phase = $more ? 'processing' : 'complete';
            $processed = CommerceShadowCodec::add((string) $checkpoint->processed_count, (string) ($retryType === null ? count($work) : 0));
            DB::table('tx_commerce_shadow_checkpoint')->where('id', $checkpoint->id)->update([
                'cursor_user_id' => $cursors['user'], 'cursor_order_id' => $cursors['order'], 'phase' => $phase,
                'processed_count' => $processed, 'row_version' => CommerceShadowCodec::add((string) $checkpoint->row_version, '1'), 'updated_at' => $now,
            ]);
            return ['mode' => 'isolated_shadow', 'legacy_authority' => 'retained', 'run_key' => $runKey,
                'phase' => $phase, 'cursor_user_id' => $cursors['user'], 'cursor_order_id' => $cursors['order'],
                'high_water_user_id' => (string) $checkpoint->high_water_user_id, 'high_water_order_id' => (string) $checkpoint->high_water_order_id,
                'processed_count' => $processed, 'unresolved_count' => (string) DB::table('tx_commerce_shadow_exception')->where('state', 'unresolved')->count(),
                'results' => $results];
        }, 3);
    }

    public function reconcile(string $runKey, string $type, string $cursor, int $limit): array
    {
        $this->guard($runKey, $limit);
        if (!in_array($type, ['user', 'order'], true)) {
            throw new InvalidArgumentException('INVALID_COMMERCE_RECONCILE_TYPE');
        }
        $cursor = CommerceShadowCodec::integer($cursor);
        return DB::transaction(function () use ($runKey, $type, $cursor, $limit) {
            $checkpoint = DB::table('tx_commerce_shadow_checkpoint')->where('run_key', $runKey)->first();
            if (!$checkpoint) {
                throw new RuntimeException('COMMERCE_RUN_MISSING');
            }
            $context = json_decode($checkpoint->details, true, flags: JSON_THROW_ON_ERROR)['context'];
            $high = $checkpoint->{'high_water_'.$type.'_id'};
            $shadowTable = $type === 'user' ? 'tx_commerce_shadow_user_opening' : 'tx_commerce_shadow_order';
            $sourceIds = DB::table('tx_'.$type)->select('id as legacy_id')->where('id', '>', $cursor)->where('id', '<=', $high);
            $shadowIds = DB::table($shadowTable)->select('legacy_'.$type.'_id as legacy_id')->where('currency', $context['currency'])
                ->where('legacy_'.$type.'_id', '>', $cursor)->where('legacy_'.$type.'_id', '<=', $high);
            $ids = DB::query()->fromSub($sourceIds->union($shadowIds), 'commerce_ids')->orderBy('legacy_id')->limit($limit)->pluck('legacy_id');
            $results = [];
            foreach ($ids as $legacyId) {
                $id = (string) $legacyId;
                $differences = [];
                try {
                    if ($type === 'user') {
                        $legacy = DB::table('tx_user')->where('id', $id)->lockForUpdate()->first();
                        $current = $legacy ? $this->userSource($legacy, $context['currency']) : null;
                    } else {
                        $pre = DB::table('tx_order')->where('id', $id)->first();
                        if ($pre) {
                            $this->lockUser(CommerceShadowCodec::integer($pre->user_id, positive: true));
                        }
                        $legacy = DB::table('tx_order')->where('id', $id)->lockForUpdate()->first();
                        $current = $legacy ? $this->orderSource($legacy, $context['currency']) : null;
                    }
                    $shadow = DB::table($shadowTable)->where('legacy_'.$type.'_id', $id)
                        ->where('currency', $context['currency'])->sharedLock()->first();
                    if ($current === null) {
                        $differences[] = ['field' => 'source', 'error_code' => 'COMMERCE_SOURCE_MISSING'];
                    } elseif ($shadow === null) {
                        $differences[] = ['field' => 'shadow', 'error_code' => 'COMMERCE_SHADOW_MISSING'];
                    } else {
                        $stored = json_decode($shadow->source_snapshot, true, flags: JSON_THROW_ON_ERROR);
                        $differences = CommerceShadowCodec::differences($current, $stored, 'source.', reverse: true);
                        $expectedHash = CommerceShadowCodec::hash($stored);
                        if (!hash_equals($expectedHash, $shadow->content_hash) || $shadow->schema_version !== self::VERSION
                            || (string) $shadow->created_at !== (string) $shadow->captured_at) {
                            $differences[] = ['field' => 'shadow.integrity', 'error_code' => 'COMMERCE_SHADOW_DAMAGED'];
                        }
                        if ($type === 'user') {
                            $differences = [...$differences, ...$this->ledger->inspect($shadow)];
                        } else {
                            $expectedEvidence = $this->amountEvidence($current);
                            $evidence = json_decode($shadow->amount_evidence, true, flags: JSON_THROW_ON_ERROR);
                            $differences = [...$differences, ...CommerceShadowCodec::differences($expectedEvidence, $evidence, 'amount_evidence.')];
                            if ($shadow->historical_terms !== 'legacy_snapshot_unknown' || (string) $shadow->legacy_user_id !== $current['legacy_user_id']) {
                                $differences[] = ['field' => 'shadow.ownership_or_terms', 'error_code' => 'COMMERCE_SHADOW_DAMAGED'];
                            }
                        }
                    }
                } catch (Throwable $error) {
                    $differences[] = ['field' => 'source_or_shadow', 'error_code' => $this->errorCode($error)];
                }
                $results[] = ['aggregate_type' => $type, 'legacy_id' => $id, 'status' => $differences ? 'different' : 'match', 'differences' => $differences];
            }
            return ['mode' => 'isolated_shadow', 'legacy_authority' => 'retained', 'run_key' => $runKey,
                'aggregate_type' => $type, 'cursor' => $ids->isEmpty() ? $cursor : (string) $ids->last(),
                'high_water_id' => (string) $high, 'checked_count' => (string) count($results),
                'difference_count' => (string) count(array_filter($results, fn ($result) => $result['status'] !== 'match')), 'results' => $results];
        });
    }

    private function captureUser(string $id, string $runKey, array $context, int $now): array
    {
        $source = $this->userSource($this->lockUser($id), $context['currency']);
        $existing = DB::table('tx_commerce_shadow_user_opening')->where('legacy_user_id', $id)->lockForUpdate()->first();
        if ($existing !== null) {
            if ($existing->currency !== $context['currency']) {
                throw new RuntimeException('COMMERCE_USER_CURRENCY_CHANGED');
            }
            $this->assertSource($existing, $source, 'COMMERCE_USER_SOURCE_CHANGED');
            if ($this->ledger->inspect($existing)) {
                throw new RuntimeException('COMMERCE_LEDGER_DAMAGED');
            }
            return $this->result('user', $id, 'unchanged', (string) $existing->id);
        }
        $journals = $this->ledger->open($source, $now);
        $shadowId = DB::table('tx_commerce_shadow_user_opening')->insertGetId($journals + [
            'legacy_user_id' => $id, 'currency' => $context['currency'], 'run_key' => $runKey, 'schema_version' => self::VERSION,
            'source_snapshot' => CommerceShadowCodec::json($source), 'content_hash' => CommerceShadowCodec::hash($source),
            'captured_at' => $now, 'created_at' => $now,
        ]);
        $after = $this->userSource($this->lockUser($id), $context['currency']);
        if (!hash_equals(CommerceShadowCodec::hash($source), CommerceShadowCodec::hash($after))) {
            throw new RuntimeException('COMMERCE_USER_CHANGED_DURING_CAPTURE');
        }
        $opening = DB::table('tx_commerce_shadow_user_opening')->where('id', $shadowId)->first();
        if ($this->ledger->inspect($opening)) {
            throw new RuntimeException('COMMERCE_LEDGER_DAMAGED');
        }
        return $this->result('user', $id, 'mapped', (string) $shadowId);
    }

    private function captureOrder(string $id, string $runKey, array $context, int $now): array
    {
        $pre = DB::table('tx_order')->where('id', $id)->first();
        if (!$pre) {
            throw new RuntimeException('COMMERCE_ORDER_MISSING');
        }
        $owner = CommerceShadowCodec::integer($pre->user_id, positive: true);
        $userSource = $this->userSource($this->lockUser($owner), $context['currency']);
        $order = DB::table('tx_order')->where('id', $id)->lockForUpdate()->first();
        if (!$order || (string) $order->user_id !== $owner) {
            throw new RuntimeException('COMMERCE_ORDER_OWNER_CHANGED');
        }
        $opening = DB::table('tx_commerce_shadow_user_opening')->where('legacy_user_id', $owner)->where('currency', $context['currency'])->lockForUpdate()->first();
        if (!$opening) {
            throw new RuntimeException('COMMERCE_USER_OPENING_MISSING');
        }
        $this->assertSource($opening, $userSource, 'COMMERCE_USER_SOURCE_CHANGED');
        if ($this->ledger->inspect($opening)) {
            throw new RuntimeException('COMMERCE_LEDGER_DAMAGED');
        }
        $source = $this->orderSource($order, $context['currency']);
        $evidence = $this->amountEvidence($source);
        $existing = DB::table('tx_commerce_shadow_order')->where('legacy_order_id', $id)->lockForUpdate()->first();
        if ($existing) {
            if ($existing->currency !== $context['currency']) {
                throw new RuntimeException('COMMERCE_ORDER_CURRENCY_CHANGED');
            }
            $this->assertSource($existing, $source, 'COMMERCE_ORDER_SOURCE_CHANGED');
            if ($existing->historical_terms !== 'legacy_snapshot_unknown' || (string) $existing->legacy_user_id !== $owner
                || !hash_equals(CommerceShadowCodec::hash($evidence), CommerceShadowCodec::hash(json_decode($existing->amount_evidence, true, flags: JSON_THROW_ON_ERROR)))) {
                throw new RuntimeException('COMMERCE_SHADOW_DAMAGED');
            }
            return $this->result('order', $id, 'unchanged', (string) $existing->id);
        }
        $shadowId = DB::table('tx_commerce_shadow_order')->insertGetId([
            'legacy_order_id' => $id, 'legacy_user_id' => $owner, 'currency' => $context['currency'], 'run_key' => $runKey,
            'schema_version' => self::VERSION, 'historical_terms' => 'legacy_snapshot_unknown',
            'source_snapshot' => CommerceShadowCodec::json($source), 'amount_evidence' => CommerceShadowCodec::json($evidence),
            'content_hash' => CommerceShadowCodec::hash($source), 'captured_at' => $now, 'created_at' => $now,
        ]);
        $afterUser = $this->userSource($this->lockUser($owner), $context['currency']);
        $afterOrder = $this->orderSource(DB::table('tx_order')->where('id', $id)->first(), $context['currency']);
        if (!hash_equals(CommerceShadowCodec::hash($source), CommerceShadowCodec::hash($afterOrder))
            || !hash_equals(CommerceShadowCodec::hash($userSource), CommerceShadowCodec::hash($afterUser))) {
            throw new RuntimeException('COMMERCE_SOURCE_CHANGED_DURING_CAPTURE');
        }
        return $this->result('order', $id, 'mapped', (string) $shadowId);
    }

    private function userSource(object $user, string $currency): array
    {
        return ['legacy_user_id' => CommerceShadowCodec::integer($user->id, positive: true), 'currency' => $currency,
            'wallet_minor' => CommerceShadowCodec::integer($user->balance), 'commission_minor' => CommerceShadowCodec::integer($user->commission_balance),
            'legacy_created_at' => CommerceShadowCodec::timestamp($user->created_at), 'legacy_updated_at' => CommerceShadowCodec::timestamp($user->updated_at)];
    }

    private function orderSource(object $order, string $currency): array
    {
        $source = ['legacy_order_id' => CommerceShadowCodec::integer($order->id, positive: true),
            'legacy_user_id' => CommerceShadowCodec::integer($order->user_id, positive: true), 'currency' => $currency];
        foreach (['plan_id', 'payment_id', 'coupon_id', 'invite_user_id'] as $field) {
            $source[$field] = CommerceShadowCodec::integer($order->$field, positive: true, nullable: $field !== 'plan_id');
        }
        foreach (['total_amount', 'handling_amount', 'discount_amount', 'surplus_amount', 'surplus_credit', 'balance_amount',
            'commission_balance', 'actual_commission_balance'] as $field) {
            $source[$field] = CommerceShadowCodec::integer($order->$field, nullable: $field !== 'total_amount');
        }
        $status = CommerceShadowCodec::integer($order->status);
        $type = CommerceShadowCodec::integer($order->type, positive: true);
        $commissionStatus = CommerceShadowCodec::integer($order->commission_status, nullable: true);
        if (!in_array($status, ['0', '1', '2', '3', '4'], true) || !in_array($type, ['1', '2', '3', '4'], true)
            || ($commissionStatus !== null && !in_array($commissionStatus, ['0', '1', '2', '3'], true))) {
            throw new RuntimeException('COMMERCE_ORDER_STATE_INVALID');
        }
        $source += ['status' => (int) $status, 'purchase_type' => (int) $type, 'commission_status' => $commissionStatus === null ? null : (int) $commissionStatus];
        if (!is_string($order->period) || !array_key_exists(PlanService::getPeriodKey($order->period), Plan::getAvailablePeriods())) {
            throw new RuntimeException('COMMERCE_ORDER_PERIOD_UNKNOWN');
        }
        $source['period'] = PlanService::getPeriodKey($order->period);
        $source['legacy_period_digest'] = CommerceShadowCodec::reference('period', $order->period);
        $source['trade_digest'] = CommerceShadowCodec::reference('trade', $order->trade_no);
        if ($source['trade_digest'] === null || DB::table('tx_order')->where('trade_no', $order->trade_no)->where('id', '!=', $order->id)->exists()) {
            throw new RuntimeException('COMMERCE_TRADE_DUPLICATE_OR_MISSING');
        }
        $source['callback_digest'] = CommerceShadowCodec::reference('callback', $order->callback_no);
        $source['legacy_callback_null'] = $order->callback_no === null;
        $source['legacy_callback_empty'] = $order->callback_no === '';
        if ($source['total_amount'] !== '0' && $source['callback_digest'] !== null) {
            if ($source['payment_id'] === null) {
                throw new RuntimeException('COMMERCE_CALLBACK_SCOPE_UNKNOWN');
            }
            $duplicateOrder = DB::table('tx_order')->where('payment_id', $source['payment_id'])
                ->where('callback_no', $order->callback_no)->where('id', '!=', $order->id)->exists();
            $duplicateRecharge = Schema::hasTable('tx_wallet_recharge') && DB::table('tx_wallet_recharge')->where('payment_id', $source['payment_id'])
                ->where('callback_no', $order->callback_no)->exists();
            if ($duplicateOrder || $duplicateRecharge) {
                throw new RuntimeException('COMMERCE_CALLBACK_DUPLICATE');
            }
        }
        foreach (['created_at', 'updated_at', 'paid_at'] as $field) {
            $source['legacy_'.$field] = CommerceShadowCodec::timestamp($order->$field, nullable: $field === 'paid_at');
        }
        $rawIds = $order->surplus_order_ids === null ? [] : json_decode($order->surplus_order_ids, true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (!is_array($rawIds) || !array_is_list($rawIds) || count($rawIds) > 1000) {
            throw new RuntimeException('COMMERCE_SURPLUS_REFERENCES_INVALID');
        }
        $ids = array_map(fn ($id) => CommerceShadowCodec::integer($id, positive: true), $rawIds);
        if (count(array_unique($ids)) !== count($ids) || in_array($source['legacy_order_id'], $ids, true)) {
            throw new RuntimeException('COMMERCE_SURPLUS_REFERENCES_INVALID');
        }
        $references = DB::table('tx_order')->whereIn('id', $ids)->get(['id', 'user_id']);
        foreach ($references as $reference) {
            if ((string) $reference->user_id !== $source['legacy_user_id']) {
                throw new RuntimeException('COMMERCE_SURPLUS_OWNER_MISMATCH');
            }
        }
        $source['surplus_order_ids'] = $ids;
        $source['legacy_surplus_order_ids_null'] = $order->surplus_order_ids === null;
        $source['surplus_reference_missing_count'] = (string) (count($ids) - $references->count());
        return $source;
    }

    private function amountEvidence(array $source): array
    {
        $due = $source['total_amount'];
        $wallet = $source['balance_amount'] ?? '0';
        $surplus = $source['surplus_amount'] ?? '0';
        $credit = $source['surplus_credit'] ?? '0';
        $fee = $source['handling_amount'] ?? '0';
        if ((int) $credit > (int) $surplus || ($credit !== '0' && ($due !== '0' || $wallet !== '0'))) {
            throw new RuntimeException('COMMERCE_SURPLUS_INCONSISTENT');
        }
        if ($surplus !== '0' && !$source['surplus_order_ids']) {
            throw new RuntimeException('COMMERCE_SURPLUS_SOURCE_UNKNOWN');
        }
        if ($due === '0' && $fee !== '0') {
            throw new RuntimeException('COMMERCE_FREE_ORDER_FEE_UNEXPLAINED');
        }
        $applied = (string) ((int) $surplus - (int) $credit);
        $net = CommerceShadowCodec::add($due, $wallet, $applied);
        return ['basis' => 'legacy_reconstruction_not_historical_quote', 'historical_terms' => 'legacy_snapshot_unknown',
            'provider_due_minor' => $due, 'fee_minor' => $fee, 'external_due_with_fee_minor' => CommerceShadowCodec::add($due, $fee),
            'wallet_applied_minor' => $wallet, 'surplus_applied_minor' => $applied, 'surplus_credit_minor' => $credit,
            'discount_minor' => $source['discount_amount'] ?? '0', 'reconstructed_item_net_minor' => $net,
            'reconstructed_item_gross_minor' => CommerceShadowCodec::add($net, $source['discount_amount'] ?? '0'),
            'wallet_disposition' => ['pending_predebit', 'processing_predebit', 'cancelled_legacy_release',
                'completed_legacy_consumption', 'discounted_legacy_consumption'][$source['status']],
            'legacy_fulfillment_recorded' => in_array($source['status'], [3, 4], true),
            'provider_collection_evidence' => $due !== '0' && $source['callback_digest'] !== null ? 'legacy_marker_unverified' : 'none'];
    }

    private function assertSource(object $shadow, array $source, string $changedCode): void
    {
        $stored = json_decode($shadow->source_snapshot, true, flags: JSON_THROW_ON_ERROR);
        if (!hash_equals(CommerceShadowCodec::hash($stored), $shadow->content_hash) || $shadow->schema_version !== self::VERSION
            || (string) $shadow->created_at !== (string) $shadow->captured_at) {
            throw new RuntimeException('COMMERCE_SHADOW_DAMAGED');
        }
        if (!hash_equals(CommerceShadowCodec::hash($source), $shadow->content_hash)) {
            throw new RuntimeException($changedCode);
        }
    }

    private function lockUser(string $id): object
    {
        $user = DB::table('tx_user')->where('id', $id)->lockForUpdate()->first();
        if (!$user) {
            throw new RuntimeException('COMMERCE_USER_MISSING');
        }
        return $user;
    }

    private function context(array $input): array
    {
        $currency = $input['currency'] ?? '';
        $snapshot = $input['snapshot_key'] ?? '';
        if (!is_string($currency) || !in_array($currency, self::CURRENCIES, true)) {
            throw new InvalidArgumentException('COMMERCE_CURRENCY_UNSUPPORTED');
        }
        if (!is_string($snapshot) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $snapshot)) {
            throw new InvalidArgumentException('COMMERCE_SNAPSHOT_KEY_REQUIRED');
        }
        return ['mapping_version' => self::VERSION, 'currency' => $currency, 'snapshot_key' => $snapshot,
            'boundary' => 'per-aggregate-locked-transaction-on-isolated-copy/v1', 'legacy_amount_unit' => 'minor'];
    }

    private function guard(string $runKey, int $limit): void
    {
        if (!config('core_shadow.isolated_copy') || !app()->environment('local', 'testing')) {
            throw new RuntimeException('ISOLATED_COPY_REQUIRED');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $runKey) || $limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('INVALID_COMMERCE_BOUNDS');
        }
    }

    private function result(string $type, string $id, string $status, string $shadowId): array
    {
        return ['aggregate_type' => $type, 'legacy_id' => $id, 'status' => $status, 'shadow_id' => $shadowId];
    }

    private function errorCode(Throwable $error): string
    {
        return ($error instanceof RuntimeException || $error instanceof InvalidArgumentException)
            && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $error->getMessage()) ? $error->getMessage() : 'COMMERCE_CAPTURE_FAILED';
    }
}
