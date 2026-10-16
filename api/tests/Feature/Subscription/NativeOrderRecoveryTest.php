<?php

namespace Tests\Feature\Subscription;

use App\Console\Kernel;
use App\Domains\Installation\InstallationAuthority;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\Subscription\PublishedOrderService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\NativeSubscriptionTestCase;

final class NativeOrderRecoveryTest extends NativeSubscriptionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationAuthority::class)->shouldReceive('assertRuntime')->andReturnNull();
    }

    public function test_committed_payment_recovers_after_dispatch_failure_and_fulfills_once(): void
    {
        $order = $this->pending();
        $dispatcher = Bus::getFacadeRoot()->dispatcher;
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('fixture queue unavailable'));
        $this->assertTrue((new OrderService($order))->paid('recovery-callback'));
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        Bus::swap($dispatcher);
        Bus::fake();

        $this->artisan('check:order', ['--limit' => 1])->assertSuccessful();
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
        $job = Bus::dispatched(OrderHandleJob::class)->first();
        $job->handle();
        $job->handle();

        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(1, DB::table('tx_subscription')->count());
        $this->assertSame(1, DB::table('tx_subscription_operation')->count());
        $this->assertSame(0, (int) DB::table('tx_user')->value('transfer_enable'));
    }

    public function test_native_scan_is_bounded_skips_legacy_and_expires_pending_reservations(): void
    {
        $expired = $this->pending('expired');
        DB::table('tx_order')->where('id', $expired->id)->update(['created_at' => time() - 7201]);
        $processing = $this->pending('processing');
        DB::table('tx_order')->where('id', $processing->id)->update(['status' => Order::STATUS_PROCESSING]);
        $fresh = $this->pending('fresh');
        $legacy = $fresh->replicate(['trade_no', 'purchase_terms_version']);
        $legacy->trade_no = 'legacy-recovery-fixture';
        $legacy->purchase_terms_version = null;
        $legacy->status = Order::STATUS_PROCESSING;
        $legacy->save();

        $this->artisan('check:order', ['--limit' => 1])->assertSuccessful();
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
        Bus::dispatched(OrderHandleJob::class)->first()->handle();
        $this->assertSame(Order::STATUS_CANCELLED, (int) $expired->fresh()->status);
        $this->assertSame(Order::STATUS_PROCESSING, (int) $processing->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING, (int) $fresh->fresh()->status);
        Bus::fake();

        $this->artisan('check:order', ['--limit' => 100])->assertSuccessful();
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
        Bus::dispatched(OrderHandleJob::class)->first()->handle();
        $this->assertSame(Order::STATUS_COMPLETED, (int) $processing->fresh()->status);
        $this->assertSame(Order::STATUS_PROCESSING, (int) $legacy->fresh()->status);
    }

    public function test_native_schedule_keeps_order_recovery_and_rejects_unbounded_limits(): void
    {
        $schedule = new Schedule();
        $method = new \ReflectionMethod(Kernel::class, 'schedule');
        $method->invoke(app(\Illuminate\Contracts\Console\Kernel::class), $schedule);
        $commands = array_map(static fn ($event) => $event->command, $schedule->events());
        $this->assertCount(2, $commands);
        $this->assertStringContainsString('core:subscriptions-tick --limit=100', $commands[0]);
        $this->assertStringContainsString('check:order --limit=100', $commands[1]);
        $this->artisan('check:order', ['--limit' => 1001])->assertFailed();
        Bus::assertNothingDispatched();
    }

    public function test_recovery_rotates_past_a_persistently_processing_low_id_order(): void
    {
        $first = $this->pending('first');
        $second = $this->pending('second');
        DB::table('tx_order')->whereIn('id', [$first->id, $second->id])->update(['status' => Order::STATUS_PROCESSING]);
        $this->artisan('check:order', ['--limit' => 1])->assertSuccessful();
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
        Bus::fake();
        $this->artisan('check:order', ['--limit' => 1])->assertSuccessful();
        Bus::assertDispatchedTimes(OrderHandleJob::class, 1);
        Bus::dispatched(OrderHandleJob::class)->first()->handle();
        $this->assertSame(Order::STATUS_PROCESSING, (int) $first->fresh()->status);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $second->fresh()->status);
    }

    private function pending(string $key = 'recovery'): Order
    {
        return app(PublishedOrderService::class)->create(
            $this->user()->id, $this->publishedOffer($this->plan(), key: $key), $key,
        );
    }
}
