<?php

namespace App\Domains\Billing;

use App\Domains\Installation\InstallationAuthority;
use Closure;
use Illuminate\Support\Facades\DB;

/** Standalone financial entry points lock installation, then the owning account. */
final class NativeBillingTransaction
{
    public function run(int $userId, Closure $operation): mixed
    {
        if (DB::transactionLevel() !== 0) {
            throw new BillingError('BILLING_OUTER_TRANSACTION_UNSUPPORTED');
        }
        if ($userId <= 0) {
            throw new BillingError('BILLING_ACCOUNT_INVALID');
        }
        return DB::transaction(function () use ($userId, $operation): mixed {
            $installation = app(InstallationAuthority::class)->assertNativeMutation();
            $user = DB::table('tx_user')->where('id', $userId)->lockForUpdate()->first();
            if ($user === null) {
                throw new BillingError('BILLING_ACCOUNT_MISSING');
            }
            return $operation($installation, $user);
        }, 3);
    }
}
