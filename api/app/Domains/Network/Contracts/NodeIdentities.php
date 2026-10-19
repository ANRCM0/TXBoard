<?php

namespace App\Domains\Network\Contracts;

/** Network owns node identity and existence; consumers receive plain data only. */
interface NodeIdentities
{
    /** @param list<string> $nodeIds @return list<array{id:string,name:string}> Existing requested nodes. */
    public function findMany(array $nodeIds): array;
}
