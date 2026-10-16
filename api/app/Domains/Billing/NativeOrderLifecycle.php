<?php

namespace App\Domains\Billing;

use App\Domains\Installation\InstallationAuthorityException;
use App\Domains\Installation\NativeRuntimeAdmission;
use App\Domains\Subscription\Services\PublishedOrderService;
use App\Domains\Subscription\Services\SubscriptionFulfillmentService;
use App\Jobs\OrderHandleJob;
use App\Models\Order;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/** Native order transitions delegate account-first locking and purchased evidence to their domain owners. */
final class NativeOrderLifecycle
{
    public function __construct(public Order $order) {}

    public function open(): void
    {
        app(SubscriptionFulfillmentService::class)->fulfill((int) $this->order->id);
        $this->order = Order::query()->useWritePdo()->findOrFail($this->order->id);
    }

    public function paid(string $callbackNo, ?int $paymentId = null, ?int $verifiedAmountMinor = null): bool
    {
        try {
            $transition = function (Order $order, $purchase, array $installation) use ($callbackNo, $paymentId, $verifiedAmountMinor) {
                // Paid purchases must enter the receipt/ledger settlement authority, not this free-order transition.
                if (($installation['runtime_version'] ?? null) === NativeRuntimeAdmission::VERSION
                    && ((string) $order->total_amount !== '0' || (int) ($order->handling_amount ?? 0) !== 0
                    || $paymentId !== null || $verifiedAmountMinor !== null)) {
                    throw new \RuntimeException('NATIVE_PAID_SETTLEMENT_REQUIRED');
                }
                // Provider identity + signed amount must be checked under the
                // same order lock as PENDING -> PROCESSING. This closes the
                // race with simultaneous checkout method changes or callbacks.
                if ($paymentId !== null && (int) $order->payment_id !== $paymentId) {
                    throw new \RuntimeException('Payment provider mismatch');
                }
                if ($verifiedAmountMinor !== null && (
                    $verifiedAmountMinor <= 0
                    || (int) $order->total_amount + (int) ($order->handling_amount ?? 0) !== $verifiedAmountMinor
                )) {
                    throw new \RuntimeException('Verified payment amount mismatch');
                }
                if ((int) $order->status !== Order::STATUS_PENDING) {
                    // A different provider transaction must never be accepted
                    // even when both requests observed PENDING before locking.
                    $sameCallback = in_array((int) $order->status, [
                        Order::STATUS_PROCESSING, Order::STATUS_COMPLETED,
                    ], true) && $order->callback_no !== null
                        && hash_equals((string) $order->callback_no, $callbackNo);
                    if (!$sameCallback) {
                        throw new \RuntimeException('Payment transition conflict');
                    }
                    return [$order, false];
                }

                $order->status = Order::STATUS_PROCESSING;
                $order->paid_at = time();
                $order->callback_no = $callbackNo;
                if (!$order->save()) {
                    throw new \RuntimeException('Failed to save order status.');
                }

                return [$order, true];
            };
            [$order, $shouldDispatch] = app(PublishedOrderService::class)
                ->transitionOrder((int) $this->order->id, $transition);

            $this->order = $order;

            if ($shouldDispatch) {
                // The paid state is already committed. A queue outage must not
                // turn a valid payment callback into a failure response.
                // check:order will recover PROCESSING orders when it runs.
                try {
                    // Dispatch immediately, while we are inside the try/catch.
                    // PendingDispatch dispatches from its destructor, which can
                    // defer exceptions beyond the intended error boundary.
                    Bus::dispatch((new OrderHandleJob($order->trade_no))->onConnection('redis'));
                } catch (\Throwable $exception) {
                    Log::error('Paid order queued for scheduler recovery after dispatch failure', [
                        'order_id' => $order->id,
                        'trade_no' => $order->trade_no,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        } catch (InstallationAuthorityException) {
            Log::error('INSTALLATION_ORDER_PAID_REJECTED');
            return false;
        } catch (\Exception $e) {
            Log::error($e);
            return false;
        }
        return true;
    }

    public function cancel(): bool
    {
        try {
            $cancelled = app(PublishedOrderService::class)->transitionOrder((int) $this->order->id,
                static function (Order $order, $purchase, array $installation): ?Order {
                    if ((int) $order->status !== Order::STATUS_PENDING) { return null; }
                    app(NativeWalletReservations::class)->releaseLocked($installation, (int) $order->user_id, $order, $purchase);
                    $order->status = Order::STATUS_CANCELLED;
                    $order->saveOrFail();
                    return $order;
                });
            if ($cancelled === null) { return false; }
            $this->order = $cancelled;
            return true;
        } catch (InstallationAuthorityException) {
            Log::error('INSTALLATION_ORDER_CANCEL_REJECTED');
            return false;
        } catch (\Exception) {
            Log::error('ORDER_CANCELLATION_FAILED');
            return false;
        }
    }
}
