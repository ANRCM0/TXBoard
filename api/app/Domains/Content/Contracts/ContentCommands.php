<?php

namespace App\Domains\Content\Contracts;

interface ContentCommands
{
    public function execute(int $actorId, string $kind, string $action, array $input, string $key): array;
}
