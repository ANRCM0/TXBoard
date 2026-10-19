<?php

namespace App\Domains\InternalApi;

use App\Domains\Billing\Contracts\GiftCards;
use App\Models\User;

final class GiftCardMutationOperations
{
    public function __construct(private readonly GiftCards $cards) {}
    public function issueBatch(User $actor, array $input, string $idempotencyKey): MutationOperationResult
    {
        try { return new MutationOperationResult($this->cards->issueBatch((int) $actor->id, $input, $idempotencyKey), 201); }
        catch (\InvalidArgumentException) { throw new OperationFailure('GIFT_CARD_INPUT_INVALID', 422); }
    }
    public function Issue(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, 'issue', $input, $idempotencyKey); }
    public function Redeem(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, 'redeem', $input, $idempotencyKey); }
    public function Revoke(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, 'revoke', $input, $idempotencyKey); }
    public function adminRevoke(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, 'revoke', $input, $idempotencyKey, true); }
    private function change(User $actor, string $action, array $input, string $key, bool $admin = false): MutationOperationResult
    {
        try { return new MutationOperationResult($this->cards->execute((int) $actor->id, $action, $input, $key, $admin), $action === 'issue' ? 201 : 200); }
        catch (\InvalidArgumentException) { throw new OperationFailure('GIFT_CARD_INPUT_INVALID', 422); }
    }
}
