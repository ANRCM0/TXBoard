<?php

namespace App\Domains\InternalApi;

use App\Domains\Billing\Contracts\GiftCards;
use App\Models\User;

final class GiftCardReadOperations
{
    public function __construct(private readonly GiftCards $cards) {}
    public function List(User $actor, array $input): ReadOperationResult { return $this->listing($actor, $input, false); }
    public function adminList(User $actor, array $input): ReadOperationResult { return $this->listing($actor, $input, true); }
    private function listing(User $actor, array $input, bool $admin): ReadOperationResult
    {
        $page = $this->cards->listing((int) $actor->id, $admin, $input['page'] ?? 1, $input['per_page'] ?? 20);
        return new ReadOperationResult($page->items, $page->meta);
    }
}
