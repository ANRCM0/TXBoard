<?php

use App\Domains\Network\NativeAccessAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// Audit events may contain sensitive destination/source IPs. Keep only 30 days.
// Production cron must run Laravel schedule:run; no cleanup in node hot paths.
Schedule::call(static function (): void {
    DB::table(NativeAccessAudit::eventsTable())
        ->where('created_at', '<', time() - NativeAccessAudit::RETENTION_DAYS * 86400)
        ->delete();
})->dailyAt('03:00')->name('prune-native-access-audit')->withoutOverlapping();
