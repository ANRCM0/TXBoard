<?php

namespace App\Domains\InternalApi;

/** Adapter-local data; authorization, status, projection and receipts stay with the executor. */
final readonly class MutationOperationResult
{
    public function __construct(public array $data, public int $status = 200, public array $meta = []) {}
}
