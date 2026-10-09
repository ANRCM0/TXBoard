<?php

namespace App\Domains\Billing;

use App\Models\Order;
use App\Models\Payment;
use App\Models\WalletRecharge;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\Plugin\HookManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Provider callbacks must retain provider-specific raw ACK bodies.
 * Shared between legacy and native URLs; no credentials or signed fields
 * are trusted until the existing provider adapter verifies the signature.
 */
final class PaymentNotificationProcessor
{
    /** @return array{status:int, body?:mixed, error?:string} */
    public function process(string $method, string $uuid, Request $request): array
    {
        HookManager::call('payment.notify.before', [$method, $uuid, $request]);
        try {
            $payment = Payment::query()->where('uuid', $uuid)
                ->where('payment', $method)->where('enable', true)->first();
            if (!$payment) {
                return ['status' => 422, 'error' => 'payment method is not available'];
            }

            $verified = (new PaymentService($method, $payment->id))->notify($request->input());
            if (!is_array($verified) || !isset($verified['trade_no'], $verified['callback_no'])
                || !is_string($verified['trade_no']) || !is_string($verified['callback_no'])
                || $verified['trade_no'] === '' || $verified['callback_no'] === '') {
                HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                return ['status' => 422, 'error' => 'verify error'];
            }

            HookManager::call('payment.notify.verified', $verified);
            // Wallet recharges are NOT subscription orders. Both payment
            // types share the same provider signature verification boundary,
            // but settle into separate transactional state machines.
            $isRecharge = WalletRecharge::query()
                ->where('trade_no', $verified['trade_no'])->exists();
            if ($isRecharge) {
                // All wallet credits require an independently verified paid
                // amount. Providers that do not supply signed paid_amount
                // must not silently credit an account.
                $signedAmount = array_key_exists('paid_amount', $verified)
                    ? $this->decimalToCents($verified['paid_amount']) : null;
                $settled = $signedAmount !== null
                    && app(WalletRechargeService::class)->settleVerified(
                        $verified['trade_no'], (int) $payment->id,
                        $verified['callback_no'], $signedAmount);
            } else {
                $settled = $this->settle($verified, $payment);
            }
            if (!$settled) {
                return ['status' => 400, 'error' => 'handle error'];
            }
            return ['status' => 200, 'body' => $verified['custom_result'] ?? 'success'];
        } catch (\Throwable $e) {
            Log::error($e);
            return ['status' => 500, 'error' => 'fail'];
        }
    }

    private function settle(array $verified, Payment $payment): bool
    {
        $order = Order::query()->where('trade_no', $verified['trade_no'])->first();
        if (!$order || (int) $order->payment_id !== (int) $payment->id) {
            return false;
        }
        $expectedAmount = (int) $order->total_amount + (int) ($order->handling_amount ?? 0);
        if ($expectedAmount <= 0) {
            return false;
        }

        // Mandatory signed amount only for the audited providers. Others still
        // require a provider-specific review before native URL rollout.
        $signedAmount = null;
        if (!array_key_exists('paid_amount', $verified)) {
            if (in_array($payment->payment, ['EPay', 'AlipayF2F'], true)) {
                return false;
            }
        } else {
            $signedAmount = $this->decimalToCents($verified['paid_amount']);
            if ($signedAmount === null || $signedAmount !== $expectedAmount) {
                return false;
            }
        }

        // A verified gateway transaction must not be replayed to move
        // funds in the wallet ledger and an ordinary subscription order.
        if (WalletRecharge::query()->where('payment_id', $payment->id)
            ->where('callback_no', $verified['callback_no'])->exists()) {
            return false;
        }

        if ((int) $order->status !== Order::STATUS_PENDING) {
            return in_array((int) $order->status, [
                Order::STATUS_PROCESSING, Order::STATUS_COMPLETED,
            ], true) && $order->callback_no !== null
                && hash_equals((string) $order->callback_no, $verified['callback_no']);
        }

        if (!(new OrderService($order))->paid(
            $verified['callback_no'], (int) $payment->id, $signedAmount
        )) {
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
