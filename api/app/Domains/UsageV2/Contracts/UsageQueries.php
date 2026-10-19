<?php

namespace App\Domains\UsageV2\Contracts;

interface UsageQueries
{
    /** Settled raw bytes, attributed to the UTC day containing batch ended_at - 1. */
    public function nodeTrafficHistory(array $nodeIds, int $days, int $now): array;

    public function listing(int $userId, UsageQuery $query): UsagePage;
}
