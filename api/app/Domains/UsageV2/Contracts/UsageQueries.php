<?php

namespace App\Domains\UsageV2\Contracts;

interface UsageQueries
{
    public function listing(int $userId, UsageQuery $query): UsagePage;
}
