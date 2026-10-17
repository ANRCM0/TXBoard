<?php

namespace App\Domains\Network\Internal;

interface InitialNetwork
{
    public function prepare(string $key, #[\SensitiveParameter] array $input): array;
}
