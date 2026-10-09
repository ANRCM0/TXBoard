<?php

namespace App\Domains\Billing;

use App\Models\Order;
use App\Models\Payment;
use App\Models\WalletRecharge;
use Illuminate\Support\Facades\DB;

/**
 * Preserve provider identity and callback verification configuration while
 * any historic subscription order or wallet top-up references the method.
 */
final class AdminPaymentSafety
{
    public function deleteUnused(int $paymentId): bool
    {
        return DB::transaction(static function () use ($paymentId): bool {
            $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);

            // This check deliberately includes cancelled, completed and
            // pending records. Ledger references are historical evidence.
            if (Order::query()->where('payment_id', $payment->id)->exists()
                || WalletRecharge::query()->where('payment_id', $payment->id)->exists()) {
                return false;
            }

            return (bool) $payment->delete();
        });
    }
}
