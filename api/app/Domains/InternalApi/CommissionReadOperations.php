<?php

namespace App\Domains\InternalApi;

use App\Domains\Billing\Contracts\Commissions;
use App\Domains\Identity\Contracts\Referrals;
use App\Models\User;

final class CommissionReadOperations
{
    public function __construct(private readonly Commissions $commissions, private readonly Referrals $referrals) {}
    public function referrals(User $actor, array $input): ReadOperationResult { return new ReadOperationResult($this->referrals->read((int) $actor->id)); }
    public function policy(User $actor, array $input): ReadOperationResult { return new ReadOperationResult($this->commissions->policy((int) $actor->id, false)); }
    public function adminPolicy(User $actor, array $input): ReadOperationResult { return new ReadOperationResult($this->commissions->policy((int) $actor->id, true)); }
    public function balance(User $actor, array $input): ReadOperationResult { return new ReadOperationResult($this->commissions->balance((int) $actor->id)); }
    public function rewards(User $actor, array $input): ReadOperationResult { return $this->listing($actor, $input, false, 'rewards'); }
    public function adminRewards(User $actor, array $input): ReadOperationResult { return $this->listing($actor, $input, true, 'rewards'); }
    public function payouts(User $actor, array $input): ReadOperationResult { return $this->listing($actor, $input, false, 'payouts'); }
    public function adminPayouts(User $actor, array $input): ReadOperationResult { return $this->listing($actor, $input, true, 'payouts'); }
    private function listing(User $actor, array $input, bool $admin, string $kind): ReadOperationResult
    {
        $page = $this->commissions->listing((int) $actor->id, $admin, $kind, $input['page'] ?? 1, $input['per_page'] ?? 20);
        return new ReadOperationResult($page->items, $page->meta);
    }
}
