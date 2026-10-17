<?php

namespace App\Domains\InternalApi;

/** Adapter-local data; the executor still owns protocol filtering and response status. */
final readonly class ReadOperationResult
{
    public function __construct(public array $data, public array $meta = []) {}
}
