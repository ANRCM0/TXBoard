<?php

namespace App\Services\Subscription;

use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Evidence for fixed existing writers. Never a shadow domain writer. */
final class LegacyUserMutationCapture
{
    public const VERSION = 'legacy-user-capture/v1';
    private const POLICIES = [
        'admin.user.update' => ['version' => self::VERSION, 'actor_domain' => 'admin_user'],
        'wallet.recharge.settle' => ['version' => 'wallet-recharge-capture/v1', 'actor_domain' => 'verified_payment_method'],
        'order.fulfill' => ['version' => 'order-fulfillment-capture/v1', 'actor_domain' => 'system_order_fulfillment'],
        'order.create' => ['version' => 'order-create-capture/v1', 'actor_domain' => 'authenticated_user'],
        'order.cancel.refund' => ['version' => 'order-cancel-refund-capture/v1', 'actor_domain' => 'system_order_cancellation'],
        'commission.transfer' => ['version' => 'commission-transfer-capture/v1', 'actor_domain' => 'authenticated_user'],
        'traffic.reset.cron' => ['version' => 'traffic-cron-reset-capture/v1', 'actor_domain' => 'system_traffic_reset'],
        'traffic.batch.settle' => ['version' => 'traffic-batch-capture/v1', 'actor_domain' => 'system_node_traffic_settlement'],
    ];
    public const FIELDS = ['id', 'plan_id', 'group_id', 'transfer_enable', 'u', 'd',
        'speed_limit', 'device_limit', 'expired_at', 'last_reset_at', 'next_reset_at',
        'reset_count', 'banned', 'balance', 'commission_balance'];
    private const NULLABLE = ['plan_id', 'group_id', 'speed_limit', 'device_limit',
        'expired_at', 'last_reset_at', 'next_reset_at'];

    /** Caller already holds the actual User exclusive lock in the original transaction. */
    public function begin(int $userId): ?array
    {
        return $this->beginWriter($userId, 'admin.user.update');
    }

    /** Called only after the native verified recharge path holds its User lock. */
    public function beginVerifiedRecharge(int $userId, int $paymentId): ?array
    {
        return $this->beginWriter($userId, 'wallet.recharge.settle', $paymentId);
    }

    /** Transaction-only: caller holds the actual PROCESSING Order and User locks. */
    public function beginOrderFulfillment(int $userId, int $orderId): ?array
    {
        return $this->beginWriter($userId, 'order.fulfill', $orderId);
    }

    /** Native create closure only: caller holds the actual authenticated owner User lock. */
    public function beginOrderCreation(int $userId): ?array
    {
        return $this->beginWriter($userId, 'order.create', $userId);
    }

    /** Native transfer passed its rejection checks and holds the authenticated owner User lock. */
    public function beginCommissionTransfer(int $userId): ?array
    {
        return $this->beginWriter($userId, 'commission.transfer', $userId);
    }

    /** Standalone due cron transaction only; caller holds the actual User exclusive lock. */
    public function beginCronTrafficReset(int $userId): ?array
    {
        return $this->beginWriter($userId, 'traffic.reset.cron', $userId);
    }

    /** Native identified batch only: caller holds Server and sorted User exclusive locks. */
    public function beginTrafficBatchSettlement(int $userId, int $serverId): ?array
    {
        return $this->beginWriter($userId, 'traffic.batch.settle', $serverId);
    }

    /** At the native refund's first User-lock position, after the PENDING Order lock. */
    public function beginOrderCancellationRefund(int $userId, int $orderId, mixed $rawRefundMinor): ?array
    {
        if (config('legacy_capture_shadow.enabled') === false) {
            return null;
        }
        return $this->safe(function () use ($userId, $orderId, $rawRefundMinor): array {
            $this->context();
            $this->integer($rawRefundMinor, '2147483647', true);
            return $this->beginWriter($userId, 'order.cancel.refund', $orderId, true);
        });
    }

    public function append(?array $before, int $actorId): void
    {
        $this->appendWriter($before, 'admin.user.update', $actorId);
    }

    public function appendVerifiedRecharge(?array $before): void
    {
        $this->appendWriter($before, 'wallet.recharge.settle');
    }

    public function appendOrderFulfillment(?array $before): void
    {
        $this->appendWriter($before, 'order.fulfill');
    }

    public function appendOrderCreation(?array $before): void
    {
        $this->appendWriter($before, 'order.create');
    }

    public function appendOrderCancellationRefund(?array $before): void
    {
        $this->appendWriter($before, 'order.cancel.refund');
    }

    public function appendCommissionTransfer(?array $before): void
    {
        $this->appendWriter($before, 'commission.transfer');
    }

    public function appendCronTrafficReset(?array $before): void
    {
        $this->appendWriter($before, 'traffic.reset.cron');
    }

    public function appendTrafficBatchSettlement(?array $before): void
    {
        $this->appendWriter($before, 'traffic.batch.settle');
    }

    private function beginWriter(int $userId, string $kind, ?int $actorId = null, bool $exclusiveSource = false): ?array
    {
        if (config('legacy_capture_shadow.enabled') === false) {
            return null;
        }
        return $this->safe(function () use ($userId, $kind, $actorId, $exclusiveSource): array {
            $context = $this->context();
            $this->require(DB::transactionLevel() > 0, 'CAPTURE_TRANSACTION_REQUIRED');
            if ($actorId !== null) { $this->integer($actorId, '2147483647', true); }
            return ['context' => $context, 'source' => $this->source($userId, $exclusiveSource), 'source_kind' => $kind,
                'source_version' => self::POLICIES[$kind]['version'], 'actor_id' => $actorId];
        });
    }

    private function appendWriter(?array $before, string $kind, ?int $adminActorId = null): void
    {
        if ($before === null) {
            return;
        }
        $this->safe(function () use ($before, $kind, $adminActorId): void {
            $this->require(DB::transactionLevel() > 0, 'CAPTURE_TRANSACTION_REQUIRED');
            $this->require(count($before) === 5 && !array_diff(array_keys($before),
                ['context', 'source', 'source_kind', 'source_version', 'actor_id'])
                && $before['source_kind'] === $kind && $before['source_version'] === self::POLICIES[$kind]['version']
                && ($kind === 'admin.user.update' ? $before['actor_id'] === null && $adminActorId !== null
                    : is_int($before['actor_id']) && $adminActorId === null), 'CAPTURE_WRITER_TICKET_INVALID');
            $actorId = $adminActorId ?? $before['actor_id'];
            $this->integer($actorId, '2147483647', true);
            $context = $this->context();
            $this->require($context === $before['context'], 'CAPTURE_CONTEXT_DRIFT');
            $source = $this->snapshot($before['source']);
            $userId = (int) $source['id'];
            $this->require(!in_array($kind, ['order.create', 'commission.transfer', 'traffic.reset.cron'], true)
                || $actorId === $userId, 'CAPTURE_WRITER_TICKET_INVALID');
            $after = $this->source($userId);
            if ($source === $after) {
                return;
            }
            $at = time();
            $contextHash = $this->hash($context);
            DB::table('tx_legacy_capture_shadow_stream')->insertOrIgnore([
                'legacy_user_id' => $userId, 'context' => $this->json($context),
                'context_hash' => $contextHash, 'last_sequence' => 0,
                'created_at' => $at, 'updated_at' => $at,
            ]);
            $stream = $this->streams()->where('legacy_user_id', $userId)->lockForUpdate()->first();
            $this->validateStream($stream, $context, $userId);
            $tail = $this->tail($stream);
            if ($tail !== null) {
                $this->require($tail['after_hash'] === $this->hash($source), 'CAPTURE_SOURCE_GAP');
            }
            $sequence = (int) $stream->last_sequence;
            $this->require($sequence < PHP_INT_MAX, 'CAPTURE_SEQUENCE_OVERFLOW');
            $event = ['stream_id' => (string) $stream->id, 'legacy_user_id' => (string) $userId,
                'sequence' => (string) ($sequence + 1), 'source_kind' => $kind,
                'source_version' => self::POLICIES[$kind]['version'], 'actor_id' => (string) $actorId,
                'before_snapshot' => $source, 'after_snapshot' => $after,
                'before_hash' => $this->hash($source), 'after_hash' => $this->hash($after),
                'previous_event_hash' => $stream->last_event_hash, 'context_hash' => $contextHash,
                'captured_at' => (string) $at];
            $event['content_hash'] = $this->hash($event);
            $row = $event;
            $row['before_snapshot'] = $this->json($source);
            $row['after_snapshot'] = $this->json($after);
            DB::table('tx_legacy_capture_shadow_event')->insert($row);
            $updated = DB::table('tx_legacy_capture_shadow_stream')->where('id', $stream->id)
                ->where('last_sequence', $stream->last_sequence)->update([
                    'last_sequence' => $event['sequence'], 'last_event_hash' => $event['content_hash'],
                    'last_after_hash' => $event['after_hash'], 'updated_at' => $at]);
            $this->require($updated === 1, 'CAPTURE_STREAM_CONFLICT');
            $map = DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $userId)->lockForUpdate()->first();
            if ($map !== null) {
                $version = $this->integer($map->row_version, (string) (PHP_INT_MAX - 1));
                $updated = DB::table('tx_legacy_subscription_map')->where('id', $map->id)
                    ->where('row_version', $version)->update(['state' => 'source_changed',
                        'error_code' => 'LEGACY_CAPTURE_PENDING', 'row_version' => (int) $version + 1,
                        'updated_at' => $at]);
                $this->require($updated === 1, 'CAPTURE_MAPPING_CONFLICT');
            }
        });
    }

    /** Fresh autonomous SELECT-only page; the User fence does not cover all legacy writers. */
    public function verify(int $userId, int $after = 0, int $limit = 100): array
    {
        return $this->safe(function () use ($userId, $after, $limit): array {
            $context = $this->context();
            $this->integer($userId, '2147483647', true);
            $this->require($after >= 0 && $limit >= 1 && $limit <= 1000, 'CAPTURE_PAGE_INVALID');
            $this->require(DB::transactionLevel() === 0, 'CAPTURE_OUTER_TRANSACTION_UNSUPPORTED');
            return DB::transaction(function () use ($userId, $after, $limit, $context): array {
                $issues = []; $projection = ['CAPTURE_PROJECTION_UNAPPLIED'];
                $current = $this->source($userId);
                $stream = $this->streams()->where('legacy_user_id', $userId)->sharedLock()->first();
                $result = ['read_only' => true, 'scope' => 'admin-user-update-evidence',
                    'legacy_user_id' => (string) $userId, 'source_fields' => self::FIELDS,
                    'global_coverage_complete' => false, 'projection_applied' => false,
                    'migration_gate_open' => false, 'after_cursor' => (string) $after,
                    'next_cursor' => (string) $after, 'high_water' => '0', 'has_more' => false,
                    'page_ok' => false, 'events' => []];
                if ($stream === null) {
                    return $result + ['issues' => ['CAPTURE_STREAM_MISSING'], 'projection_issues' => $projection];
                }
                $this->validateStream($stream, $context, $userId);
                $tail = $this->tail($stream);
                $this->require($tail !== null, 'CAPTURE_STREAM_EMPTY');
                $high = (int) $stream->last_sequence;
                $this->require($after <= $high, 'CAPTURE_CURSOR_AHEAD');
                $result['high_water'] = (string) $high;
                if ($this->hash($current) !== $stream->last_after_hash) {
                    $issues[] = 'CAPTURE_SOURCE_GAP';
                }
                $previous = null; $previousAfter = null;
                if ($after > 0) {
                    $anchor = $this->events()->where('stream_id', $stream->id)
                        ->where('sequence', $after)->sharedLock()->first();
                    $this->require($anchor !== null, 'CAPTURE_CHAIN_GAP');
                    $anchor = $this->event($anchor, $stream);
                    $previous = $anchor['content_hash']; $previousAfter = $anchor['after_hash'];
                }
                $rows = $this->events()->where('stream_id', $stream->id)
                    ->where('sequence', '>', $after)->orderBy('sequence')->limit($limit + 1)->sharedLock()->get();
                $result['has_more'] = $rows->count() > $limit;
                $sequence = $after;
                foreach ($rows->take($limit) as $row) {
                    $event = $this->event($row, $stream);
                    $this->require((int) $event['sequence'] === ++$sequence
                        && $event['previous_event_hash'] === $previous
                        && ($previousAfter === null || $event['before_hash'] === $previousAfter), 'CAPTURE_CHAIN_GAP');
                    $this->require($sequence <= $high, 'CAPTURE_STREAM_TAMPERED');
                    $previous = $event['content_hash']; $previousAfter = $event['after_hash'];
                    $result['events'][] = ['id' => (string) $row->id, 'sequence' => $event['sequence'],
                        'content_hash' => $event['content_hash'], 'source_kind' => $event['source_kind'],
                        'source_version' => $event['source_version'], 'actor_id' => $event['actor_id'],
                        'actor_domain' => self::POLICIES[$event['source_kind']]['actor_domain']]
                        + (in_array($event['source_kind'], ['order.fulfill', 'order.create', 'order.cancel.refund', 'commission.transfer', 'traffic.reset.cron', 'traffic.batch.settle'], true)
                            ? ['capture_boundary' => 'transaction-only'] : []);
                    $result['next_cursor'] = (string) $sequence;
                }
                $this->require($result['has_more'] || $sequence === $high, 'CAPTURE_CHAIN_GAP');
                $projection = array_merge($projection, $this->projection($userId));
                $result['page_ok'] = $issues === [];
                return $result + ['issues' => $issues, 'projection_issues' => array_values(array_unique($projection))];
            });
        });
    }

    private function projection(int $userId): array
    {
        $slot = DB::table('tx_subscription_slot')->where('user_id', $userId)->sharedLock()->first();
        $subs = DB::table('tx_subscription')->where('user_id', $userId)->orderBy('id')->limit(1001)->sharedLock()->get();
        if ($subs->count() > 1000) { return ['CAPTURE_SUBSCRIPTION_HISTORY_LIMIT']; }
        $map = DB::table('tx_legacy_subscription_map')->where('legacy_user_id', $userId)->sharedLock()->first();
        if (!$map) { return ['CAPTURE_MAPPING_MISSING']; }
        $issues = [];
        if ($map->state !== 'source_changed' || $map->error_code !== 'LEGACY_CAPTURE_PENDING') {
            $issues[] = 'CAPTURE_MAPPING_NOT_INVALIDATED';
        }
        $sub = $subs->firstWhere('id', $map->subscription_id);
        if (!$sub || (string) $sub->current_terms_id !== (string) $map->terms_id) {
            $issues[] = 'CAPTURE_TERMS_CONFLICT';
        }
        if (!$slot || (string) $slot->subscription_id !== (string) $map->subscription_id) {
            $issues[] = 'CAPTURE_SLOT_CONFLICT';
        }
        $period = DB::table('tx_usage_period')->where('id', $map->usage_period_id)->sharedLock()->first();
        if (!$period || (string) $period->subscription_id !== (string) $map->subscription_id
            || $period->boundary_kind !== 'known') { $issues[] = 'CAPTURE_PERIOD_BOUNDARY_UNPROVEN'; }
        // Deliberately no batch/grant locks: usage settlement locks these before subscriptions.
        // First ordinary read occurs after the current User/subscription fences.
        $batches = DB::table('tx_usage_batch')->where('status', '!=', 'settled')
            ->orderBy('id')->select(['id', 'frozen_hash', DB::raw('length(frozen_entries) as payload_bytes')])->limit(1001)->get();
        if ($batches->count() > 1000) { $issues[] = 'CAPTURE_USAGE_SCOPE_LIMIT'; }
        else {
            $bytes = 0;
            foreach ($batches as $batch) {
                $bytes += (int) $batch->payload_bytes;
                if ($batch->payload_bytes > 262144 || $bytes > 2097152) {
                    $issues[] = 'CAPTURE_USAGE_SCOPE_LIMIT';
                    return $issues;
                }
            }
            // Same fresh ordinary-read snapshot; load only evidence whose size passed the guards.
            foreach ($batches as $batch) {
                $payload = DB::table('tx_usage_batch')->where('id', $batch->id)->value('frozen_entries');
                $entries = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($entries) || count($entries) > 1000
                    || !hash_equals($batch->frozen_hash, $this->hash($entries))) {
                    $issues[] = 'CAPTURE_USAGE_SCOPE_UNPROVEN'; break;
                }
                foreach ($entries as $entry) {
                    if (!is_array($entry) || !isset($entry['subscription_id'])) { $issues[] = 'CAPTURE_USAGE_SCOPE_UNPROVEN'; break; }
                    if ((string) $entry['subscription_id'] === (string) $map->subscription_id) { $issues[] = 'CAPTURE_PENDING_USAGE'; }
                }
            }
        }
        if (DB::table('tx_usage_entry')->where('subscription_id', $map->subscription_id)->exists()) {
            $issues[] = 'CAPTURE_ACCEPTED_USAGE';
        }
        return $issues;
    }

    private function source(int $userId, bool $exclusive = false): array
    {
        $query = DB::table('tx_user')->where('id', $userId)->select(self::FIELDS);
        $row = ($exclusive ? $query->lockForUpdate() : $query->sharedLock())->first();
        $this->require($row !== null, 'CAPTURE_SOURCE_MISSING');
        return $this->snapshot((array) $row);
    }

    private function snapshot(array $raw): array
    {
        $this->require(count($raw) === count(self::FIELDS) && !array_diff(self::FIELDS, array_keys($raw)), 'CAPTURE_SOURCE_INVALID');
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $raw[$field];
            if ($field === 'banned') {
                $this->require(in_array($value, [true, false, 0, 1, '0', '1'], true), 'CAPTURE_SOURCE_INVALID');
                $out[$field] = (bool) $value;
            } elseif ($value === null && in_array($field, self::NULLABLE, true)) {
                $out[$field] = null;
            } else {
                $out[$field] = $this->integer($value,
                    in_array($field, ['transfer_enable', 'u', 'd', 'expired_at'], true) ? '9223372036854775807' : '2147483647',
                    in_array($field, ['id', 'plan_id', 'group_id'], true));
            }
        }
        return $out;
    }

    private function context(): array
    {
        $settings = config('legacy_capture_shadow');
        $this->require(is_array($settings) && count($settings) === 4
            && !array_diff(array_keys($settings), ['enabled', 'run_key', 'billing_timezone', 'currency']), 'CAPTURE_CONTEXT_INVALID');
        $this->require(config('legacy_capture_shadow.enabled') === true
            && app()->environment(['local', 'testing']) && config('core_shadow.isolated_copy') === true, 'CAPTURE_ISOLATED_COPY_REQUIRED');
        $run = config('legacy_capture_shadow.run_key'); $tz = config('legacy_capture_shadow.billing_timezone');
        $currency = config('legacy_capture_shadow.currency');
        $this->require(is_string($run) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,63}$/D', $run)
            && is_string($tz) && in_array($tz, DateTimeZone::listIdentifiers(), true)
            && in_array($currency, ['USD', 'CNY', 'EUR', 'GBP', 'HKD', 'SGD', 'CAD', 'AUD', 'NZD', 'TWD'], true), 'CAPTURE_CONTEXT_INVALID');
        return ['version' => self::VERSION, 'run_key' => $run, 'billing_timezone' => $tz, 'currency' => $currency];
    }

    private function validateStream(?object $stream, array $context, int $userId): void
    {
        $this->require($stream !== null && (string) $stream->legacy_user_id === (string) $userId, 'CAPTURE_STREAM_TAMPERED');
        $this->integer($stream->id, (string) PHP_INT_MAX, true);
        $this->integer($stream->last_sequence, (string) PHP_INT_MAX);
        $this->require($this->json(json_decode($stream->context, true, 16, JSON_THROW_ON_ERROR)) === $this->json($context)
            && $stream->context_hash === $this->hash($context), 'CAPTURE_CONTEXT_DRIFT');
    }

    private function tail(object $stream): ?array
    {
        $row = $this->events()->where('stream_id', $stream->id)
            ->orderByDesc('sequence')->sharedLock()->first();
        if ((string) $stream->last_sequence === '0') {
            $this->require($row === null && $stream->last_event_hash === null && $stream->last_after_hash === null, 'CAPTURE_STREAM_TAMPERED');
            return null;
        }
        $this->require($row !== null, 'CAPTURE_STREAM_TAMPERED');
        $event = $this->event($row, $stream);
        $this->require($event['sequence'] === (string) $stream->last_sequence
            && $event['content_hash'] === $stream->last_event_hash && $event['after_hash'] === $stream->last_after_hash, 'CAPTURE_STREAM_TAMPERED');
        return $event;
    }

    private function event(object $row, object $stream): array
    {
        $payload = [];
        foreach (['stream_id', 'legacy_user_id', 'sequence', 'source_kind', 'source_version', 'actor_id',
            'before_snapshot', 'after_snapshot', 'before_hash', 'after_hash', 'previous_event_hash', 'context_hash', 'captured_at'] as $field) {
            $value = $row->$field;
            if (in_array($field, ['stream_id', 'legacy_user_id', 'sequence', 'actor_id', 'captured_at'], true)) {
                $value = $this->integer($value, in_array($field, ['legacy_user_id', 'actor_id'], true)
                    ? '2147483647' : (string) PHP_INT_MAX, $field !== 'captured_at');
            } elseif (in_array($field, ['before_snapshot', 'after_snapshot'], true)) {
                $this->require(is_string($value) && strlen($value) <= 8192, 'CAPTURE_EVENT_TAMPERED');
                $raw = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
                $value = $this->snapshot($raw);
                $this->require($this->json($raw) === $this->json($value), 'CAPTURE_EVENT_TAMPERED');
            }
            $payload[$field] = $value;
        }
        $this->require($payload['stream_id'] === (string) $stream->id
            && $payload['legacy_user_id'] === (string) $stream->legacy_user_id
            && isset(self::POLICIES[$payload['source_kind']])
            && $payload['source_version'] === self::POLICIES[$payload['source_kind']]['version']
            && (!in_array($payload['source_kind'], ['order.create', 'commission.transfer', 'traffic.reset.cron'], true)
                || $payload['actor_id'] === $payload['legacy_user_id'])
            && $payload['context_hash'] === $stream->context_hash
            && $payload['before_snapshot']['id'] === $payload['legacy_user_id']
            && $payload['after_snapshot']['id'] === $payload['legacy_user_id']
            && $payload['before_hash'] === $this->hash($payload['before_snapshot'])
            && $payload['after_hash'] === $this->hash($payload['after_snapshot'])
            && $payload['before_hash'] !== $payload['after_hash']
            && $row->content_hash === $this->hash($payload), 'CAPTURE_EVENT_TAMPERED');
        return $payload + ['content_hash' => $row->content_hash];
    }

    private function integer(mixed $value, string $max, bool $positive = false): string
    {
        $this->require(is_int($value) || is_string($value), 'CAPTURE_SOURCE_INVALID');
        $value = (string) $value;
        $this->require(preg_match('/^(0|[1-9][0-9]*)$/D', $value)
            && (!$positive || $value !== '0')
            && (strlen($value) < strlen($max) || strlen($value) === strlen($max) && strcmp($value, $max) <= 0), 'CAPTURE_SOURCE_INVALID');
        return $value;
    }

    private function streams(): \Illuminate\Database\Query\Builder
    {
        return DB::table('tx_legacy_capture_shadow_stream')->select(['id', 'legacy_user_id', 'context_hash',
            'last_sequence', 'last_event_hash', 'last_after_hash', DB::raw('substr(context, 1, 2049) as context')]);
    }

    private function events(): \Illuminate\Database\Query\Builder
    {
        return DB::table('tx_legacy_capture_shadow_event')->select(['id', 'stream_id', 'legacy_user_id', 'sequence',
            'source_kind', 'source_version', 'actor_id', 'before_hash', 'after_hash', 'previous_event_hash',
            'context_hash', 'captured_at', 'content_hash',
            DB::raw('substr(before_snapshot, 1, 8193) as before_snapshot'),
            DB::raw('substr(after_snapshot, 1, 8193) as after_snapshot')]);
    }

    private function json(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) { return $item; }
            if (!array_is_list($item)) { ksort($item, SORT_STRING); }
            return array_map($normalize, $item);
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    private function hash(array $value): string { return hash('sha256', $this->json($value)); }
    private function require(bool $ok, string $code): void { if (!$ok) { throw new LegacyCaptureShadowException($code); } }
    private function safe(callable $callback): mixed
    {
        try { return $callback(); }
        catch (LegacyCaptureShadowException $e) { throw $e; }
        catch (Throwable) { throw new LegacyCaptureShadowException('CAPTURE_STORAGE_OR_EVIDENCE_FAILED'); }
    }
}
