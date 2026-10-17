<?php

namespace App\Domains\InternalApi;

use App\Domains\UsageV2\Contracts\UsageQueries;
use App\Domains\UsageV2\Contracts\UsageQuery;
use App\Domains\UsageV2\Contracts\UsageTargetMissing;
use App\Models\User;

final class UsageReadOperations
{
    public function __construct(private readonly UsageQueries $queries) {}

    public function listing(User $actor, array $input): ?ReadOperationResult
    {
        try {
            $page = $this->queries->listing((int) $actor->id, new UsageQuery(
                isset($input['subscription_id']) ? (int) $input['subscription_id'] : null,
                (int) ($input['after_id'] ?? 0), (int) ($input['limit'] ?? 100),
            ));
        } catch (UsageTargetMissing) {
            return null;
        }
        return new ReadOperationResult($page->items,
            ['schema' => 'native-usage-periods/v1', 'next_after_id' => $page->nextAfterId]);
    }
}
