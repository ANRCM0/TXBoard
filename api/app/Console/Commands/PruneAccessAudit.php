<?php

namespace App\Console\Commands;

use App\Domains\Network\NativeAccessAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneAccessAudit extends Command
{
    protected $signature = 'access-audit:prune';
    protected $description = 'Delete AccessAudit events older than the configured 30-day retention window';

    public function handle(): int
    {
        $before = time() - NativeAccessAudit::RETENTION_DAYS * 86400;
        $deleted = 0;
        // Bounded batches avoid large production table locks during cleanup.
        do {
            $ids = DB::table(NativeAccessAudit::eventsTable())
                ->where('created_at', '<', $before)
                ->orderBy('id')->limit(1000)->pluck('id')->all();
            if (!$ids) break;
            $count = DB::table(NativeAccessAudit::eventsTable())->whereIn('id', $ids)->delete();
            $deleted += $count;
        } while ($count > 0);
        $this->info("Removed {$deleted} expired access-audit events");
        return self::SUCCESS;
    }
}
