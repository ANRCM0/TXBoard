<?php

namespace App\Domains\UsageV2\Contracts;

final readonly class UsagePage
{
    public function __construct(public array $items, public ?string $nextAfterId) {}
}
