<?php

namespace App\Domains\Network\Contracts;

interface NodeAuthorizationQueries
{
    public function publicTrust(): array;
    public function snapshot(NodePrincipal $principal): array;
}
