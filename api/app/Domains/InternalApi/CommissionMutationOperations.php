<?php

namespace App\Domains\InternalApi;

use App\Domains\Billing\Contracts\Commissions;
use App\Domains\Identity\Contracts\Referrals;
use App\Models\User;

final class CommissionMutationOperations
{
    public function __construct(private readonly Commissions $commissions, private readonly Referrals $referrals) {}
    public function code(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->referral($actor, 'code', $input, $idempotencyKey); }
    public function bind(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->referral($actor, 'bind', $input, $idempotencyKey); }
    public function policy(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, true, 'policy', $input, $idempotencyKey); }
    public function requestPayout(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, false, 'payout', $input, $idempotencyKey); }
    public function approve(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, true, 'approve', $input, $idempotencyKey); }
    public function reject(User $actor, array $input, string $idempotencyKey): MutationOperationResult { return $this->change($actor, true, 'reject', $input, $idempotencyKey); }
    private function referral(User $actor, string $action, array $input, string $key): MutationOperationResult
    {
        try { return new MutationOperationResult($this->referrals->execute((int) $actor->id, $action, $input, $key)); }
        catch (\InvalidArgumentException) { throw new OperationFailure('REFERRAL_INPUT_INVALID', 422); }
        catch (\DomainException $failure) { throw new OperationFailure($failure->getMessage() === 'REFERRAL_ALREADY_BOUND' ? 'REFERRAL_ALREADY_BOUND' : 'REFERRAL_UNAVAILABLE', 409); }
    }
    private function change(User $actor, bool $admin, string $action, array $input, string $key): MutationOperationResult
    {
        try { return new MutationOperationResult($this->commissions->execute((int) $actor->id, $admin, $action, $input, $key)); }
        catch (\InvalidArgumentException) { throw new OperationFailure('COMMISSION_INPUT_INVALID', 422); }
    }
}
