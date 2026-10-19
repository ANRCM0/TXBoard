<?php

namespace App\Domains\Support\Contracts;

interface TicketCommands
{
    public function create(int $actorId, array $input, string $key): array;
    public function reply(int $actorId, array $input, string $key, bool $admin = false): array;
    public function close(int $actorId, array $input, string $key, bool $admin = false): array;
    public function claim(int $actorId, array $input, string $key): array;
    public function unassign(int $actorId, array $input, string $key): array;
}
