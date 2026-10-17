<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Internal\PaymentPackageReferences;
use Illuminate\Support\Facades\DB;

final class NativePaymentPackageReferences implements PaymentPackageReferences
{
    public function retained(string $code): bool
    {
        if (DB::transactionLevel() < 1) throw new BillingError('PAYMENT_TRANSACTION_REQUIRED');
        return DB::table('tx_payment_dispatch')->where('plugin_code', $code)->sharedLock()->exists();
    }
}
