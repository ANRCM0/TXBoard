<?php

namespace App\Domains\InternalApi;

use App\Domains\Billing\Contracts\CouponAdministration;
use App\Models\User;

final class CouponMutationOperations
{
    public function __construct(private readonly CouponAdministration $coupons) {}
    public function create(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return $this->change($actor, 'create', $input, $idempotencyKey);
    }
    public function disable(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return $this->change($actor, 'disable', $input, $idempotencyKey);
    }
    private function change(User $actor, string $action, array $input, string $key): MutationOperationResult
    {
        try {
            return new MutationOperationResult($this->coupons->execute((int) $actor->id, $action, $input, $key), $action === 'create' ? 201 : 200);
        } catch (\InvalidArgumentException) { throw new OperationFailure('COUPON_INPUT_INVALID', 422); }
    }
}
