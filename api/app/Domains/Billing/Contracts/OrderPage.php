<?php

namespace App\Domains\Billing\Contracts;

final readonly class OrderPage
{
    public function __construct(public array $items, public array $meta) {}
}
