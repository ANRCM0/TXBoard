<?php

namespace App\Domains\InternalApi;

use App\Domains\Billing\Contracts\GiftCards;
use App\Models\User;

final class GiftBatchReadOperations
{
    public function __construct(private readonly GiftCards $cards) {}

    public function listing(User $actor, array $input): ReadOperationResult { return $this->page($actor, $input, false); }
    public function adminListing(User $actor, array $input): ReadOperationResult { return $this->page($actor, $input, true); }
    public function find(User $actor, array $input): ?ReadOperationResult { return $this->detail($actor, $input, false); }
    public function adminFind(User $actor, array $input): ?ReadOperationResult { return $this->detail($actor, $input, true); }

    private function page(User $actor, array $input, bool $admin): ReadOperationResult
    {
        $page = $this->cards->batches((int) $actor->id, $admin, $input['page'] ?? 1, $input['per_page'] ?? 20);
        return new ReadOperationResult($page->items, $page->meta);
    }

    private function detail(User $actor, array $input, bool $admin): ?ReadOperationResult
    {
        $batch = $this->cards->batch((int) $actor->id, $input['batch_id'], $admin);
        return $batch === null ? null : new ReadOperationResult($batch);
    }
}
