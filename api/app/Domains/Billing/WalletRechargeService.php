<?php

namespace App\Domains\Billing;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletRecharge;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer-funded wallet top-ups, distinct from subscription orders.
 * Never grant wallet credit when a checkout starts; only a verified provider
 * callback may settle a pending recharge.
 */
final class WalletRechargeService
{
    public const MIN_AMOUNT_MINOR = 100;
    public const MAX_AMOUNT_MINOR = 500000;
    // Existing v2_user.balance is a signed 32-bit SQL INTEGER.
    public const MAX_BALANCE_MINOR = 2147483647;
    public const VERIFIED_RECHARGE_PROVIDERS = ['EPay', 'AlipayF2F'];

    public function create(int $userId, int $amountMinor, int $paymentId, string $requestKey): WalletRecharge
    {
        if ($amountMinor < self::MIN_AMOUNT_MINOR || $amountMinor > self::MAX_AMOUNT_MINOR
            || !Str::isUuid($requestKey)) {
            throw new ApiException('Invalid recharge amount or idempotency key', 422);
        }
        return DB::transaction(static function () use (
            $userId, $amountMinor, $paymentId, $requestKey
        ): WalletRecharge {
            // Serialize create requests per user so the pending limit cannot
            // be evaded by concurrent POSTs.
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            if ((int) $user->balance > self::MAX_BALANCE_MINOR - $amountMinor) {
                throw new ApiException('Wallet balance limit exceeded', 409);
            }
            $existing = WalletRecharge::query()
                ->where('user_id', $userId)->where('request_key', $requestKey)->first();
            if ($existing) {
                if ((int) $existing->amount_minor !== $amountMinor ||
                    (int) $existing->payment_id !== $paymentId) {
                    throw new ApiException('Idempotency key belongs to another recharge', 409);
                }
                return $existing;
            }
            if (WalletRecharge::query()->where('user_id', $userId)
                ->where('status', WalletRecharge::STATUS_PENDING)
                ->where('created_at', '>=', time() - 86400)->count() >= 20) {
                throw new ApiException('Too many recent pending recharges', 409);
            }
            $method = Payment::query()->whereKey($paymentId)
                ->where('enable', true)->first();
            if (!$method || !in_array((string) $method->payment,
                self::VERIFIED_RECHARGE_PROVIDERS, true)) {
                throw new ApiException('Verified recharge payment method unavailable', 422);
            }

            // Same payment fee semantics as native OrderCheckout: store exact
            // gross amount before any provider request is dispatched.
            $feeMinor = (int) round(
                $amountMinor * ((float) $method->handling_fee_percent / 100)
                + (float) $method->handling_fee_fixed
            );
            if ($feeMinor < 0 || $feeMinor > self::MAX_AMOUNT_MINOR) {
                throw new ApiException('Invalid payment fee', 422);
            }
            return WalletRecharge::query()->create([
                'user_id' => $userId,
                'payment_id' => $paymentId,
                'trade_no' => 'WR' . strtoupper(bin2hex(random_bytes(14))),
                'request_key' => $requestKey,
                'amount_minor' => $amountMinor,
                'fee_minor' => $feeMinor,
                'status' => WalletRecharge::STATUS_PENDING,
            ]);
        });
    }

    public function checkout(int $userId, string $tradeNo, ?string $token): array
    {
        $recharge = WalletRecharge::query()->where('user_id', $userId)
            ->where('trade_no', $tradeNo)->firstOrFail();
        if ((int) $recharge->status !== WalletRecharge::STATUS_PENDING) {
            throw new ApiException('Recharge already settled', 409);
        }
        $payment = Payment::query()->whereKey($recharge->payment_id)
            ->where('enable', true)->first();
        if (!$payment || !in_array((string) $payment->payment,
            self::VERIFIED_RECHARGE_PROVIDERS, true)) {
            throw new ApiException('Verified recharge payment method unavailable', 422);
        }
        $provider = new PaymentService((string) $payment->payment, $payment->id);
        // Provider calls happen outside DB locks and are not confirmation of
        // payment; an already delivered callback may win this race safely.
        $result = $provider->pay([
            'trade_no' => $recharge->trade_no,
            'total_amount' => (int) $recharge->amount_minor + (int) $recharge->fee_minor,
            'user_id' => $userId,
            'stripe_token' => $token,
            'return_path' => '/#/wallet',
        ]);
        if (!is_array($result) || !isset($result['type'], $result['data'])) {
            throw new ApiException('Invalid payment response', 502);
        }
        return ['type' => (int) $result['type'], 'data' => $result['data']];
    }

    /**
     * Called ONLY after the existing payment adapter verified a provider
     * signature and returned a signed amount. A missing signed amount is
     * never sufficient to credit a wallet.
     */
    public function settleVerified(string $tradeNo, int $paymentId,
        string $callbackNo, int $paidAmountMinor): bool
    {
        if ($callbackNo === '' || strlen($callbackNo) > 191 || $paidAmountMinor <= 0) {
            return false;
        }
        return DB::transaction(static function () use (
            $tradeNo, $paymentId, $callbackNo, $paidAmountMinor
        ): bool {
            $recharge = WalletRecharge::query()->where('trade_no', $tradeNo)
                ->lockForUpdate()->first();
            if (!$recharge || (int) $recharge->payment_id !== $paymentId ||
                (int) $recharge->amount_minor + (int) $recharge->fee_minor !== $paidAmountMinor) {
                return false;
            }
            if ((int) $recharge->status === WalletRecharge::STATUS_PAID) {
                return $recharge->callback_no !== null
                    && hash_equals((string) $recharge->callback_no, $callbackNo);
            }
            // Provider transaction must not credit a separate recharge/order.
            if (WalletRecharge::query()->where('payment_id', $paymentId)
                ->where('callback_no', $callbackNo)->whereKeyNot($recharge->id)->exists() ||
                Order::query()->where('payment_id', $paymentId)
                ->where('callback_no', $callbackNo)->exists()) {
                return false;
            }
            $user = User::query()->whereKey($recharge->user_id)
                ->lockForUpdate()->first();
            if (!$user || (int) $user->balance > self::MAX_BALANCE_MINOR - (int) $recharge->amount_minor) {
                return false;
            }
            $user->balance = (int) $user->balance + (int) $recharge->amount_minor;
            $user->saveOrFail();
            $recharge->status = WalletRecharge::STATUS_PAID;
            $recharge->callback_no = $callbackNo;
            $recharge->paid_at = time();
            $recharge->saveOrFail();
            return true;
        });
    }
}
