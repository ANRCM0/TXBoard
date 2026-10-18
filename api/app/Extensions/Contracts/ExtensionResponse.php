<?php

namespace App\Extensions\Contracts;

final readonly class ExtensionResponse
{
    public function __construct(public array $data, public int $status = 200) {}
}
