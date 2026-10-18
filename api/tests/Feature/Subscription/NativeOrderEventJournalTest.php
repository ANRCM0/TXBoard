<?php

namespace Tests\Feature\Subscription;

use App\Domains\Billing\NativeOrderEventJournal;
use App\Domains\Billing\NativeOrderLifecycle;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\Billing\NativeOrderQueries;
use App\Domains\Billing\Contracts\OrderListQuery;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeSubscriptionTestCase;

final class NativeOrderEventJournalTest extends NativeSubscriptionTestCase
{
    public function test_creation_and_cancellation_are_durable_cursor_ordered_and_idempotent(): void
    {
        $user = $this->user();
        $plan = $this->plan();
        $offer = $this->publishedOffer($plan);
        $order = app(PublishedOrderService::class)->create($user->id, $offer, 'events-a');

        $this->assertSame([['type' => 'created', 'status' => 0]], DB::table('tx_native_order_event')
            ->where('order_id', $order->id)->get(['type', 'status'])->map(fn ($row) => (array) $row)->all());

        DB::transaction(function () use ($order): void {
            app(NativeOrderEventJournal::class)->recordLocked($order, 'created');
        });
        $first = app(NativeOrderEventJournal::class)->page($order, '0', 1);
        $this->assertSame('1', $first->meta['next_after_sequence']);
        $this->assertTrue($first->meta['has_more'] === false);
        $this->assertSame('created', $first->items[0]['type']);

        $this->assertTrue((new NativeOrderLifecycle($order))->cancel());
        $second = app(NativeOrderEventJournal::class)->page($order->fresh(), '1', 10);
        $this->assertSame(['cancelled'], array_column($second->items, 'type'));
        $this->assertSame(2, DB::table('tx_native_order_event')->where('order_id', $order->id)->count());
        $this->assertTrue($second->meta['history_complete']);
        $this->assertFalse((new NativeOrderLifecycle($order->fresh()))->cancel());
        $empty = app(NativeOrderEventJournal::class)->page($order->fresh(), '2', 10);
        $this->assertSame([], $empty->items);
        $this->assertSame('2', $empty->meta['next_after_sequence']);
        $this->assertFalse($empty->meta['has_more']);
    }

    public function test_reconnecting_reader_uses_sequence_even_with_equal_or_out_of_order_timestamps(): void
    {
        $user = $this->user();
        $order = app(PublishedOrderService::class)->create($user->id, $this->publishedOffer($this->plan()), 'reconnect');
        $journal = app(NativeOrderEventJournal::class);
        $cursor = $journal->page($order, '0', 1)->meta['next_after_sequence'];
        DB::transaction(function () use ($order, $journal): void {
            $order->status = Order::STATUS_PROCESSING;
            $order->saveOrFail();
            $journal->recordLocked($order, 'payment_accepted', 10);
            $journal->recordLocked($order, 'payment_accepted', 999);
            $order->status = Order::STATUS_COMPLETED;
            $order->saveOrFail();
            $journal->recordLocked($order, 'fulfilled', 10);
        });
        $first = $journal->page($order, $cursor, 1);
        $this->assertSame(['payment_accepted'], array_column($first->items, 'type'));
        $this->assertTrue($first->meta['has_more']);
        $second = $journal->page($order, $first->meta['next_after_sequence'], 1);
        $this->assertSame(['fulfilled'], array_column($second->items, 'type'));
        $this->assertSame('3', $second->meta['next_after_sequence']);
        $this->assertFalse($second->meta['has_more']);
        $this->assertSame($first->items, $journal->page($order, $cursor, 1)->items);
        $this->assertSame($first->items[0]['occurred_at'], $second->items[0]['occurred_at']);
    }

    public function test_event_insert_failure_rolls_back_order_transition_and_retry_keeps_contiguous_sequence(): void
    {
        $user = $this->user();
        $order = app(PublishedOrderService::class)->create($user->id, $this->publishedOffer($this->plan()), 'rollback');
        $inject = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$inject): void {
            if ($inject && preg_match('/insert into ["`]tx_native_order_event["`]/i', $sql)) {
                $inject = false;
                throw new \RuntimeException('INJECTED_ORDER_EVENT_FAILURE');
            }
        });
        $this->assertFalse((new NativeOrderLifecycle($order))->cancel());
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertSame(1, DB::table('tx_native_order_event')->where('order_id', $order->id)->count());
        $this->assertTrue((new NativeOrderLifecycle($order->fresh()))->cancel());
        $this->assertSame(['1', '2'], array_column(app(NativeOrderEventJournal::class)->page($order, '0', 100)->items, 'sequence'));
    }

    public function test_pre_upgrade_order_does_not_fabricate_missing_history_and_user_reads_are_scoped(): void
    {
        $user = $this->user();
        $other = $this->user();
        $order = app(PublishedOrderService::class)->create($user->id, $this->publishedOffer($this->plan()), 'historical');
        // Model a pre-migration native order: no backfill invents prior transitions.
        DB::table('tx_native_order_event')->where('order_id', $order->id)->delete();
        $queries = app(NativeOrderQueries::class);
        $empty = $queries->events($user->id, $order->trade_no, '0', 10);
        $this->assertSame([], $empty->items);
        $this->assertFalse($empty->meta['history_complete']);
        $this->assertNull($queries->events($other->id, $order->trade_no, '0', 10));
        $this->assertTrue((new NativeOrderLifecycle($order))->cancel());
        $page = $queries->adminEvents($order->trade_no, '0', 10);
        $this->assertSame(['cancelled'], array_column($page->items, 'type'));
        $this->assertSame('1', $page->meta['next_after_sequence']);
        $this->assertFalse($page->meta['history_complete']);
    }

    public function test_invalid_cursor_and_limit_are_rejected_without_querying_or_mutating_history(): void
    {
        $user = $this->user();
        $order = app(PublishedOrderService::class)->create($user->id, $this->publishedOffer($this->plan()), 'invalid');
        foreach ([['-1', 1], ['01', 1], ['1e2', 1], ['9223372036854775808', 1], ['0', 0], ['0', 101]] as [$cursor, $limit]) {
            try {
                app(NativeOrderEventJournal::class)->page($order, $cursor, $limit);
                $this->fail('Invalid cursor or limit accepted.');
            } catch (\InvalidArgumentException $error) {
                $this->assertSame('NATIVE_ORDER_EVENT_QUERY_INVALID', $error->getMessage());
            }
        }
        $this->assertSame(1, DB::table('tx_native_order_event')->where('order_id', $order->id)->count());
    }

    public function test_populated_history_refuses_destructive_migration_rollback(): void
    {
        $user = $this->user();
        app(PublishedOrderService::class)->create($user->id, $this->publishedOffer($this->plan()), 'migration');
        try {
            (require database_path('migrations/2026_10_19_000001_create_native_order_events.php'))->down();
            $this->fail('Populated history was dropped.');
        } catch (\RuntimeException $error) {
            $this->assertSame('NATIVE_ORDER_EVENTS_POPULATED_ROLLBACK_REFUSED', $error->getMessage());
        }
        $this->assertSame(1, DB::table('tx_native_order_event')->count());
    }

    public function test_creation_event_failure_cannot_leave_an_order_or_purchase_snapshot(): void
    {
        $user = $this->user();
        $offer = $this->publishedOffer($this->plan());
        $inject = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$inject): void {
            if ($inject && preg_match('/insert into ["`]tx_native_order_event["`]/i', $sql)) {
                $inject = false;
                throw new \RuntimeException('INJECTED_ORDER_EVENT_FAILURE');
            }
        });
        try {
            app(PublishedOrderService::class)->create($user->id, $offer, 'creation-atomic');
            $this->fail('Creation was committed without its event.');
        } catch (\RuntimeException $error) {
            $this->assertSame('INJECTED_ORDER_EVENT_FAILURE', $error->getMessage());
        }
        foreach (['tx_order', 'tx_order_purchase_terms', 'tx_native_order_event'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_native_order_summary_and_detail_use_frozen_purchase_after_plan_changes(): void
    {
        $user = $this->user();
        $plan = $this->plan(['name' => 'Purchased plan', 'transfer_enable' => 2]);
        $order = app(PublishedOrderService::class)->create($user->id, $this->publishedOffer($plan), 'frozen-read');
        DB::table('tx_plan')->where('id', $plan->id)->update(['name' => 'Replacement plan', 'transfer_enable' => 100]);
        $queries = app(NativeOrderQueries::class);
        $this->assertSame('Purchased plan', $queries->listing($user->id, new OrderListQuery())->items[0]['plan']['name']);
        $this->assertSame('Purchased plan', $queries->find($user->id, $order->trade_no)['plan']['name']);
        $this->assertSame('USD', $queries->find($user->id, $order->trade_no)['currency']);
        $this->assertSame(PublishedOrderService::VERSION, $queries->find($user->id, $order->trade_no)['purchase_terms_version']);
        $this->assertSame(['id' => (int) $plan->id, 'name' => 'Purchased plan', 'traffic_limit_bytes' => 2147483648],
            $queries->detail($user->id, $order->trade_no)['plan']);
        $this->assertSame('Purchased plan', $queries->adminFind($order->trade_no)['plan']['name']);
        $this->assertNull($queries->detail($this->user()->id, $order->trade_no));
    }
}
