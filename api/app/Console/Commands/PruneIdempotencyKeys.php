<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune';
    protected $description = 'Delete expired order idempotency records in bounded batches';

    public function handle(): int
    {
        $total = 0;
        do {
            $ids = DB::table('idempotency_keys')
                ->where('expires_at', '<=', time())
                ->orderBy('expires_at')->orderBy('id')
                ->limit(500)->pluck('id')->all();
            if (!$ids) break;
            $removed = DB::table('idempotency_keys')->whereIn('id', $ids)
                ->where('expires_at', '<=', time())->delete();
            $total += $removed;
        } while ($removed > 0);
        $this->info("Pruned {$total} expired idempotency keys");
        return self::SUCCESS;
    }
}
