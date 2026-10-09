<?php

namespace App\Domains\Billing;

use App\Models\Coupon;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * An applied coupon is financial history, even for cancelled/expired orders.
 * It can be disabled but must not be hard-deleted while orders reference it.
 */
final class AdminCouponSafety
{
    public function deleteUnused(int $id): bool
    {
        return DB::transaction(static function () use ($id): bool {
            $coupon = Coupon::query()->lockForUpdate()->findOrFail($id);
            if (Order::query()->where('coupon_id', $coupon->id)->exists()) {
                return false;
            }
            return (bool) $coupon->delete();
        });
    }
}
