<?php

namespace App\Domains\Identity\Internal;

interface InitialIdentity
{
    public function prepare(string $key, #[\SensitiveParameter] array $input): array;
}
