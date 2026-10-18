<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Contracts\OrderPage;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/** Display history only. Payment receipts, ledger and fulfillment remain authoritative. */
final class NativeOrderEventJournal
{
    private const STATES = ['created' => Order::STATUS_PENDING, 'payment_accepted' => Order::STATUS_PROCESSING,
        'fulfilled' => Order::STATUS_COMPLETED, 'cancelled' => Order::STATUS_CANCELLED];

    /** Caller owns the aggregate transaction and account-first order lock. No external I/O. */
    public function recordLocked(Order $order, string $type, ?int $occurredAt = null): void
    {
        if (DB::transactionLevel() === 0 || $order->purchase_terms_version !== PublishedOrderService::VERSION
            || !array_key_exists($type, self::STATES) || (int) $order->status !== self::STATES[$type]) {
            throw new LogicException('NATIVE_ORDER_EVENT_TRANSITION_INVALID');
        }
        $stored = Order::query()->whereKey($order->id)->lockForUpdate()->first();
        if ($stored === null || (int) $stored->status !== (int) $order->status
            || $stored->purchase_terms_version !== PublishedOrderService::VERSION) {
            throw new LogicException('NATIVE_ORDER_EVENT_ORDER_INVALID');
        }
        // A current locking read avoids an older RR snapshot skipping a committed predecessor.
        $rows = DB::table('tx_native_order_event')->where('order_id', $order->id)
            ->orderBy('sequence')->lockForUpdate()->get();
        $existing = $rows->firstWhere('type', $type);
        if ($existing !== null) {
            if ((int) $existing->status !== (int) $order->status) {
                throw new LogicException('NATIVE_ORDER_EVENT_REPLAY_INVALID');
            }
            return;
        }
        DB::table('tx_native_order_event')->insert([
            'order_id' => $order->id, 'sequence' => $rows->isEmpty() ? 1 : (int) $rows->last()->sequence + 1,
            'type' => $type, 'status' => self::STATES[$type], 'occurred_at' => $occurredAt ?? time(),
        ]);
    }

    /** Order identity and user/admin authorization are resolved by the owner before this call. */
    public function page(Order $order, string $afterSequence, int $limit): OrderPage
    {
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $afterSequence)
            || strlen($afterSequence) > 19 || (strlen($afterSequence) === 19 && strcmp($afterSequence, (string) PHP_INT_MAX) > 0)
            || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('NATIVE_ORDER_EVENT_QUERY_INVALID');
        }
        $base = DB::table('tx_native_order_event')->useWritePdo()->where('order_id', $order->id);
        $rows = (clone $base)->where('sequence', '>', $afterSequence)->orderBy('sequence')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $items = $rows->take($limit)->map(static fn ($row): array => [
            'sequence' => (string) $row->sequence, 'type' => $row->type, 'status' => (int) $row->status,
            'occurred_at' => Carbon::createFromTimestampUTC((int) $row->occurred_at)->toIso8601String(),
        ])->all();
        // Existing orders have no fabricated pre-upgrade history. Empty polls retain their cursor.
        return new OrderPage($items, ['next_after_sequence' => $items === [] ? $afterSequence : $items[array_key_last($items)]['sequence'],
            'has_more' => $more, 'history_complete' => (clone $base)->where('type', 'created')->exists()]);
    }
}
