<?php

namespace App\Domains\Subscription\Internal;

interface InitialCatalog
{
    public function prepare(string $key, array $input): array;
}
