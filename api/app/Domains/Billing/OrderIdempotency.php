<?php

namespace App\Domains\Billing;

use App\Exceptions\ApiException;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * MySQL-backed order-write idempotency. The caller MUST already hold the
 * tx_user row lock inside the same transaction as the business mutation.
 * No Redis locks or separate "claim" transactions: a crash rolls back both
 * the order (including wallet/coupon reservations) and the idempotency claim.
 */
final class OrderIdempotency
{
    public const OPERATION_CREATE = 'order.create';
    private const TABLE = 'idempotency_keys';

    public static function clientKey(?string $header): ?string
    {
        if ($header === null) {
            // Existing native clients without the header retain old behavior.
            // Gateway writes always require a key; new clients should send one.
            return null;
        }
        $value = trim($header);
        if (!preg_match('/\A[A-Za-z0-9._~-]{1,128}\z/D', $value)) {
            throw new IdempotencyException('Invalid Idempotency-Key header', 400, 'VALIDATION_ERROR');
        }
        return $value;
    }

    public static function keyHash(int $userId, string $operation, string $key): string
    {
        return hash('sha256', $userId . ':' . $operation . ':' . $key);
    }

    public static function requestHash(array $body): string
    {
        return hash('sha256', json_encode(self::sortRecursively($body),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function sortRecursively(array $body): array
    {
        if (!array_is_list($body)) {
            ksort($body, SORT_STRING);
        }
        foreach ($body as &$value) {
            if (is_array($value)) {
                $value = self::sortRecursively($value);
            }
        }
        unset($value);
        return $body;
    }

    /**
     * Invoke only under a transaction and a locked user row. Returns the
     * existing order before purchase preflight on a successful replay.
     */
    public static function run(int $userId, ?string $key, array $body, \Closure $create): Order
    {
        if ($key === null) {
            return $create();
        }

        $operation = self::OPERATION_CREATE;
        $keyHash = self::keyHash($userId, $operation, $key);
        $requestHash = self::requestHash($body);
        $now = time();
        $ttlSeconds = max(3600, min(604800, (int) config('idempotency.ttl_hours', 24) * 3600));
        $timeout = max(60, (int) config('idempotency.in_progress_timeout_seconds', 900));
        $table = DB::table(self::TABLE);
        $record = (clone $table)->where('user_id', $userId)
            ->where('operation', $operation)
            ->where('key_hash', $keyHash)
            ->lockForUpdate()->first();

        if ($record && (int) $record->expires_at <= $now) {
            // A physically retained expired key must not block new attempts.
            (clone $table)->where('id', $record->id)->delete();
            $record = null;
        }

        if ($record) {
            if (!hash_equals((string) $record->request_hash, $requestHash)) {
                throw new IdempotencyException('Idempotency-Key was already used for different parameters',
                    409, 'IDEMPOTENCY_CONFLICT');
            }
            if ($record->status === 'succeeded') {
                $order = Order::query()->where('user_id', $userId)
                    ->where('trade_no', $record->trade_no)->first();
                if (!$order) {
                    throw new IdempotencyException('Original order is not available', 409, 'ORDER_CONFLICT');
                }
                return $order;
            }
            if ($record->status === 'in_progress' && (int) $record->updated_at > $now - $timeout) {
                throw new IdempotencyException('Order request is still processing',
                    409, 'IDEMPOTENCY_IN_PROGRESS');
            }
            // In-progress leases become recoverable after timeout. A failed
            // transaction rolls back the transition, allowing another retry.
            (clone $table)->where('id', $record->id)->update([
                'status' => 'in_progress',
                'updated_at' => $now,
                'expires_at' => $now + $ttlSeconds,
            ]);
            $id = (int) $record->id;
        } else {
            $id = (int) $table->insertGetId([
                'user_id' => $userId,
                'operation' => $operation,
                'key_hash' => $keyHash,
                'request_hash' => $requestHash,
                'status' => 'in_progress',
                'trade_no' => null,
                'result_snapshot' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'expires_at' => $now + $ttlSeconds,
            ]);
        }

        // A failure or process termination rolls back both row and order:
        // there cannot be a committed successful order without its ledger.
        $order = $create();
        (clone $table)->where('id', $id)->update([
            'status' => 'succeeded',
            'trade_no' => $order->trade_no,
            'result_snapshot' => json_encode(['trade_no' => $order->trade_no], JSON_THROW_ON_ERROR),
            'updated_at' => time(),
        ]);
        return $order;
    }
}
