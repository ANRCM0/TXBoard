<?php

namespace App\Domains\Subscription\Contracts;

interface CatalogQueries
{
    public const SCHEMA = 'native-plan-offers/v1';

    /** Fresh owned read transaction; published, non-secret offers, bounded to 256. */
    public function available(?int $planId = null): array;
}
