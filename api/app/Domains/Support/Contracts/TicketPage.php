<?php

namespace App\Domains\Support\Contracts;

final readonly class TicketPage
{
    public function __construct(public array $items, public array $meta) {}
}
