<?php

namespace App\Domains\InternalApi;

use App\Domains\Billing\Contracts\OrderCancellation;
use App\Domains\Billing\Contracts\OrderCheckout;
use App\Domains\Billing\Contracts\Recharges;
use App\Domains\Subscription\Contracts\Purchases;
use App\Models\User;

final class CommerceMutationOperations
{
    public function __construct(private readonly Purchases $purchases, private readonly OrderCheckout $checkout,
        private readonly OrderCancellation $cancellation, private readonly Recharges $recharges) {}

    public function purchase(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        try {
            return new MutationOperationResult($this->purchases->purchase((int) $actor->id,
                (int) $input['offer_id'], $idempotencyKey)->toArray(), 201);
        } catch (\App\Domains\Billing\IdempotencyException|\App\Domains\Installation\InstallationAuthorityException $error) {
            throw $error;
        } catch (\DomainException|\RuntimeException) {
            throw new OperationFailure('ORDER_REJECTED', 409);
        }
    }

    public function checkout(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->checkout->start((int) $actor->id, $input['trade_no'],
            isset($input['method']) ? (int) $input['method'] : null, null, $idempotencyKey,
            (bool) ($input['wallet'] ?? false), $input['wallet_amount_minor'] ?? null));
    }

    public function cancel(User $actor, array $input, string $idempotencyKey): ?MutationOperationResult
    {
        $cancelled = $this->cancellation->cancel((int) $actor->id, $input['trade_no']);
        if ($cancelled === null) throw new OperationFailure('NOT_FOUND', 404);
        if (!$cancelled) throw new OperationFailure('ORDER_CONFLICT', 409);
        return new MutationOperationResult(['ok' => true]);
    }

    public function recharge(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->recharges->create((int) $actor->id, $input['amount_minor'],
            (int) $input['payment_method_id'], $idempotencyKey), 201);
    }

    public function rechargeCheckout(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        return new MutationOperationResult($this->recharges->checkout((int) $actor->id, $input['trade_no']));
    }
}
