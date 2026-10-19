<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Contracts\OrderPage;
use App\Domains\Billing\Contracts\OrderPaymentQueries;
use App\Domains\Identity\Internal\CurrentSessionAuthorization;
use App\Domains\Installation\InstallationAuthority;
use Illuminate\Support\Facades\DB;

/** Discover existing dispatches without creating a payment or contacting its provider. */
final class NativeOrderPaymentQueries implements OrderPaymentQueries
{
    public function __construct(private readonly CurrentSessionAuthorization $sessions,
        private readonly NativePaymentAttempts $attempts) {}

    public function attempts(int $actorId, string $tradeNo, ?string $beforeId = null, int $limit = 20): ?OrderPage
    {
        if ($actorId < 1 || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $tradeNo) !== 1
            || $beforeId !== null && (preg_match('/\A[1-9][0-9]{0,18}\z/D', $beforeId) !== 1
                || strlen($beforeId) === 19 && strcmp($beforeId, (string) PHP_INT_MAX) > 0)
            || $limit < 1 || $limit > 20) {
            throw new \InvalidArgumentException('PAYMENT_ORDER_QUERY_INVALID');
        }
        if (DB::transactionLevel() !== 0) throw new BillingError('BILLING_OUTER_TRANSACTION_UNSUPPORTED');
        return DB::transaction(function () use ($actorId, $tradeNo, $beforeId, $limit): ?OrderPage {
            $installation = app(InstallationAuthority::class)->assertNativeRead();
            $this->sessions->assertCurrentLocked($actorId);
            return $this->attempts->orderPageLocked($installation, $actorId, $tradeNo, $beforeId, $limit);
        }, 3);
    }
}
