<?php

namespace App\Domains\InternalApi;

use App\Domains\Billing\Contracts\CouponAdministration;
use App\Models\User;

final class CouponReadOperations
{
    public function __construct(private readonly CouponAdministration $coupons) {}
    public function listing(User $actor, array $input): ReadOperationResult
    {
        $page = $this->coupons->listing((int) $actor->id, $input['page'] ?? 1, $input['per_page'] ?? 20);
        return new ReadOperationResult($page->items, $page->meta);
    }
    public function find(User $actor, array $input): ?ReadOperationResult
    {
        $row = $this->coupons->find((int) $actor->id, $input['coupon_id']);
        return $row === null ? null : new ReadOperationResult($row);
    }
}
