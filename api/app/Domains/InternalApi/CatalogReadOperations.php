<?php

namespace App\Domains\InternalApi;

use App\Domains\Subscription\Contracts\CatalogQueries;

final class CatalogReadOperations
{
    public function __construct(private readonly CatalogQueries $catalog) {}

    public function listing(array $input): ReadOperationResult
    {
        return new ReadOperationResult($this->catalog->available(), ['schema' => CatalogQueries::SCHEMA]);
    }
}
