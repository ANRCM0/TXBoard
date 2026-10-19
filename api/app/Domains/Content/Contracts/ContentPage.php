<?php

namespace App\Domains\Content\Contracts;

final readonly class ContentPage
{
    public function __construct(public array $items, public array $meta) {}
}
