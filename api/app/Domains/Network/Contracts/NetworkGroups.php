<?php

namespace App\Domains\Network\Contracts;

interface NetworkGroups
{
    public function read(?int $page = null, ?int $perPage = null): array;
}
