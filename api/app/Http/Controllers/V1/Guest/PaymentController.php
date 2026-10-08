<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\Plugin\HookManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        HookManager::call('payment.notify.before', [$method, $uuid, $request]);

        try {
            // A valid signature for one gateway must never settle an order using another.
            $payment = Payment::where('uuid', $uuid)
                ->where('payment', $method)
                ->where('enable', true)
                ->first();
            if (!$payment) {
                return $this->fail([422, 'payment method is not available']);
            }

            $paymentService = new PaymentService($method, $payment->id);
            $verify = $paymentService->notify($request->input());

            if (!is_array($verify)
                || !isset($verify['trade_no'], $verify['callback_no'])
                || !is_string($verify['trade_no'])
                || !is_string($verify['callback_no'])
                || $verify['trade_no'] === ''
                || $verify['callback_no'] === '') {
                HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                return $this->fail([422, 'verify error']);
            }

            HookManager::call('payment.notify.verified', $verify);
            if (!$this->handle($verify, $payment)) {
                return $this->fail([400, 'handle error']);
            }

            return $verify['custom_result'] ?? 'success';
        } catch (\Throwable $e) {
            Log::error($e);
            return $this->fail([500, 'fail']);
        }
    }

    private function handle(array $verify, Payment $payment): bool
    {
        $order = Order::where('trade_no', $verify['trade_no'])->first();
        if (!$order || (int) $order->payment_id !== (int) $payment->id) {
            return false;
        }

        $expectedAmount = (int) $order->total_amount + (int) ($order->handling_amount ?? 0);
        if ($expectedAmount <= 0) {
            return false;
        }

        // Signed amounts are mandatory for the two gateways checked in this release.
        // Other payment integrations need their own provider-specific verification review.
        if (!array_key_exists('paid_amount', $verify)) {
            if (in_array($payment->payment, ['EPay', 'AlipayF2F'], true)) {
                return false;
            }
        } elseif ($this->decimalToCents($verify['paid_amount']) !== $expectedAmount) {
            return false;
        }

        if ((int) $order->status !== Order::STATUS_PENDING) {
            // A cancelled order must not be acknowledged as paid. Only an identical
            // provider transaction can be safely acknowledged as a duplicate.
            return in_array((int) $order->status, [
                Order::STATUS_PROCESSING,
                Order::STATUS_COMPLETED,
            ], true) && $order->callback_no !== null
                && hash_equals((string) $order->callback_no, $verify['callback_no']);
        }

        if (!(new OrderService($order))->paid($verify['callback_no'])) {
            return false;
        }

        HookManager::call('payment.notify.success', $order);
        return true;
    }

    private function decimalToCents(mixed $amount): ?int
    {
        if (!is_string($amount) && !is_int($amount)) {
            return null;
        }
        $value = (string) $amount;
        if (strlen($value) > 13
            || !preg_match('/^(0|[1-9][0-9]*)(\.[0-9]{1,2})?$/D', $value)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
